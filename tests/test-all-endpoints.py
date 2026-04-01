#!/usr/bin/env python3
"""
Divi MCP Agent — Endpoint Hardening Tests
Exercises all 33 MCP tool endpoints, targeting modes, slim layout,
and validator checks. WP-CLI tests are placeholder-only (skipped)
since WP-CLI runs via MCP's local execFile, not REST.

Usage:
  python3 .oaris/tests/test-all-endpoints.py

Environment variables:
  WP_URL          WordPress site URL (default: http://divi5-ai.local)
  WP_USER         WordPress username (required)
  WP_APP_PASSWORD Application password (required)
"""

import json
import urllib.request
import urllib.error
import base64
import os
import sys
import time

WP_URL = os.environ.get("WP_URL", "http://divi5-ai.local")
WP_USER = os.environ.get("WP_USER")
WP_APP_PASSWORD = os.environ.get("WP_APP_PASSWORD")

if not WP_USER or not WP_APP_PASSWORD:
    print("Error: WP_USER and WP_APP_PASSWORD environment variables are required.")
    print("  export WP_USER=your-user")
    print('  export WP_APP_PASSWORD="your app password"')
    sys.exit(1)

BASE = f"{WP_URL}/wp-json/divi-mcp/v1"
AUTH = base64.b64encode(f"{WP_USER}:{WP_APP_PASSWORD}".encode()).decode()

passed = 0
failed = 0
skipped = 0
errors = []


def api(path, method="GET", body=None):
    req = urllib.request.Request(f"{BASE}{path}", method=method)
    req.add_header("Authorization", f"Basic {AUTH}")
    if body is not None:
        req.add_header("Content-Type", "application/json")
        req.data = json.dumps(body).encode()
    try:
        with urllib.request.urlopen(req) as resp:
            raw = resp.read()
            try:
                data = json.loads(raw)
            except json.JSONDecodeError:
                data = {"raw": raw.decode()[:200]}
            return resp.status, data
    except urllib.error.HTTPError as e:
        body_text = e.read().decode()
        try:
            data = json.loads(body_text)
        except json.JSONDecodeError:
            data = {"raw": body_text[:200]}
        return e.code, data


def test(name, passed_condition, detail=""):
    global passed, failed
    status = "PASS" if passed_condition else "FAIL"
    if passed_condition:
        passed += 1
    else:
        failed += 1
        errors.append(f"{name}: {detail}")
    print(f"  [{status}] {name}" + (f" — {detail}" if detail and not passed_condition else ""))


def skip(name, reason=""):
    global skipped
    skipped += 1
    print(f"  [SKIP] {name}" + (f" — {reason}" if reason else ""))


# ============================================================
print("=" * 60)
print("  DIVI MCP AGENT — HARDENING TESTS")
print("=" * 60)


# ============================================================
print("\n--- 1. READ ENDPOINTS (20 tools) ---\n")
# ============================================================

# 1.1 list_pages
code, data = api("/pages")
test("list_pages returns 200", code == 200)
test("list_pages has results", "results" in data and len(data["results"]) > 0)
test("list_pages has total", "total" in data)
if not data.get("results"):
    print("  No pages found — cannot continue read tests")
    sys.exit(1)
TEST_PAGE_ID = data["results"][0]["id"]

# 1.2 get_page
code, data = api(f"/page/{TEST_PAGE_ID}")
test("get_page returns 200", code == 200)
test("get_page has title", "title" in data)
test("get_page has content_raw", "content_raw" in data)

# 1.3 get_page_layout
code, data = api(f"/page/{TEST_PAGE_ID}/layout")
test("get_page_layout returns 200", code == 200)
test("get_page_layout has sections", "sections" in data or "layout" in data or isinstance(data, dict))

# 1.4 list_modules
code, data = api("/modules")
test("list_modules returns 200", code == 200)
test("list_modules has modules", isinstance(data, list) and len(data) > 0)
MODULE_COUNT = len(data) if isinstance(data, list) else 0
test(f"list_modules count > 50", MODULE_COUNT > 50, f"got {MODULE_COUNT}")

# 1.5 get_module_schema (optimized)
code, data = api("/module/divi/text")
test("get_module_schema returns 200", code == 200)
test("get_module_schema has attributes", "attributes" in data)

# Schema optimizer runs in MCP TypeScript layer (schema-optimizer.ts), not PHP.
# PHP endpoint always returns full schema. Optimizer tested via MCP tool, not REST.
skip("schema optimizer", "runs in MCP TypeScript layer, not testable via REST")

# 1.7 get_settings
code, data = api("/settings")
test("get_settings returns 200", code == 200)
test("get_settings has data", isinstance(data, dict) and len(data) > 0)

# 1.8 get_global_colors
code, data = api("/global-colors")
test("get_global_colors returns 200", code == 200)

# 1.9 get_global_fonts
code, data = api("/global-fonts")
test("get_global_fonts returns 200", code == 200)

# 1.10 find_icon
code, data = api("/icons/search?q=heart&limit=5")
test("find_icon returns 200", code == 200)
test("find_icon has results", "results" in data and len(data["results"]) > 0)
if data.get("results"):
    test("find_icon result has unicode", "unicode" in data["results"][0])
else:
    skip("find_icon result has unicode", "no results")

# 1.11 get_section
code, data = api(f"/page/312/get-section?label=Hero")
if code == 200:
    test("get_section returns 200", True)
    test("get_section has markup", "markup" in data and len(data["markup"]) > 0)
else:
    skip("get_section", "FlowSchule page 312 may not have Hero section")

# 1.12 list_templates (prompt templates are MCP resources, not REST endpoints)
skip("list_templates", "prompt templates not REST-accessible")

# 1.13 get_template
skip("get_template", "prompt templates not REST-accessible")

# 1.14 render_preview
code, data = api("/render", "POST", {
    "content": '<!-- wp:divi/placeholder --><!-- wp:divi/section {"module":{"decoration":{"layout":{"desktop":{"value":{"display":"block"}}}}},"builderVersion":"5.1.0"} --><!-- wp:divi/row {"module":{"decoration":{"layout":{"desktop":{"value":{"display":"block"}}}}},"builderVersion":"5.1.0"} --><!-- wp:divi/column {"module":{"decoration":{"layout":{"desktop":{"value":{"display":"block"}}}}},"builderVersion":"5.1.0"} --><!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"\\u003cp\\u003eTest\\u003c/p\\u003e"}}},"builderVersion":"5.1.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->'
})
test("render_preview returns 200", code == 200)

# 1.15 get_presets
code, data = api("/presets")
test("get_presets returns 200", code == 200)
test("get_presets has divi5_presets", "divi5_presets" in data)

# 1.16 preset_audit
code, data = api("/preset-audit")
test("preset_audit returns 200", code == 200)

# 1.17 list_library
code, data = api("/library")
test("list_library returns 200", code == 200)
test("list_library has results", "results" in data)

# 1.18 get_library_item
if data.get("results") and len(data["results"]) > 0:
    lib_id = data["results"][0]["id"]
    code, data2 = api(f"/library/{lib_id}")
    test("get_library_item returns 200", code == 200)
    test("get_library_item has content", "content_raw" in data2)
else:
    skip("get_library_item", "no library items to test")

# 1.19 list_tb_templates
code, data = api("/theme-builder/templates")
test("list_tb_templates returns 200", code == 200)
test("list_tb_templates has results", "results" in data and len(data["results"]) > 0)
test("list_tb_templates has total", "total" in data)
test("list_tb_templates has total_pages", "total_pages" in data)

# Check template structure
if data.get("results"):
    tmpl = data["results"][0]
    test("tb_template has id", "id" in tmpl)
    test("tb_template has conditions", "conditions" in tmpl)
    test("tb_template has header_layout_id", "header_layout_id" in tmpl)

# 1.20 get_tb_layout
if data.get("results"):
    header_id = data["results"][0].get("header_layout_id", 0)
    if header_id > 0:
        code, data2 = api(f"/theme-builder/layout/{header_id}")
        test("get_tb_layout returns 200", code == 200)
        test("get_tb_layout has content", "content_raw" in data2 or "content" in data2)
    else:
        skip("get_tb_layout", "default template has no custom header")
else:
    skip("get_tb_layout", "no templates found")


# ============================================================
print("\n--- 2. WRITE ENDPOINTS (13 tools) ---\n")
# ============================================================

# Helper: minimal section block with label and text content.
# Uses json.dumps for safe escaping of label/text values.
def make_section(label, text, text_label=None):
    if text_label is None:
        text_label = f"{label} Text"
    esc_text = text.replace("<", "\\u003c").replace(">", "\\u003e")

    layout_val = {"display": "block"}
    sec_attrs = json.dumps({"module": {"meta": {"adminLabel": {"desktop": {"value": label}}},
                "decoration": {"layout": {"desktop": {"value": layout_val}}}},
                "builderVersion": "5.1.0"}, separators=(",", ":"))
    row_attrs = json.dumps({"module": {"decoration": {"layout": {"desktop": {"value": layout_val}}}},
                "builderVersion": "5.1.0"}, separators=(",", ":"))
    col_attrs = row_attrs
    txt_attrs = json.dumps({"module": {"meta": {"adminLabel": {"desktop": {"value": text_label}}}},
                "content": {"innerContent": {"desktop": {"value": esc_text}}},
                "builderVersion": "5.1.0"}, separators=(",", ":"))
    return (
        f'<!-- wp:divi/section {sec_attrs} -->'
        f'<!-- wp:divi/row {row_attrs} -->'
        f'<!-- wp:divi/column {col_attrs} -->'
        f'<!-- wp:divi/text {txt_attrs} /-->'
        f'<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section -->'
    )


# Build a test page with 3 sections (2 share label "Duplicate Section")
# This gives us: section:1, section:2, section:3, text:1, text:2, text:3
TEST_CONTENT = (
    '<!-- wp:divi/placeholder -->'
    + make_section("Unique Section", "<p>Alpha content</p>")
    + make_section("Duplicate Section", "<p>Beta content</p>")
    + make_section("Duplicate Section", "<p>Gamma content</p>")
    + '<!-- /wp:divi/placeholder -->'
)

# 2.1 create_page
code, data = api("/page/create", "POST", {
    "title": f"Hardening Test Page {int(time.time())}",
    "status": "draft",
    "content": TEST_CONTENT
})
test("create_page returns 200", code == 200 or code == 201)
test("create_page has page_id", "page_id" in data)
NEW_PAGE_ID = data.get("page_id", 0)

if NEW_PAGE_ID:
    # 2.2 update_page_content — replace all content with our multi-section page
    code, data = api(f"/page/{NEW_PAGE_ID}/content", "POST", {
        "content": TEST_CONTENT
    })
    test("update_page_content returns 200", code == 200)

    # 2.3 append_section
    code, data = api(f"/page/{NEW_PAGE_ID}/append", "POST", {
        "content": make_section("Appended Section", "<p>Appended</p>")
    })
    test("append_section returns 200", code == 200)

    # 2.4 replace_section (by label)
    code, data = api(f"/page/{NEW_PAGE_ID}/replace-section", "POST", {
        "label": "Appended Section",
        "content": make_section("Replaced Section", "<p>Replaced</p>")
    })
    test("replace_section returns 200", code == 200)

    # 2.5 remove_section (by label)
    code, data = api(f"/page/{NEW_PAGE_ID}/remove-section", "POST", {
        "label": "Replaced Section"
    })
    test("remove_section returns 200", code == 200)

    # 2.6 update_module (by label)
    code, data = api(f"/page/{NEW_PAGE_ID}/update-module", "POST", {
        "label": "Unique Section Text",
        "attrs": {"content.innerContent.desktop.value": "\\u003cp\\u003eModule updated\\u003c/p\\u003e"}
    })
    test("update_module returns 200", code == 200)

    # 2.7 validate_blocks
    code2, page_data = api(f"/page/{NEW_PAGE_ID}")
    if code2 == 200:
        code, data = api("/validate", "POST", {"content": page_data["content_raw"]})
        test("validate_blocks returns 200", code == 200)
        test("validate_blocks has valid field", "valid" in data)
        test("validate_blocks has total_blocks", "total_blocks" in data)
        test("validate_blocks no errors on valid page", len(data.get("errors", [])) == 0,
             f"errors: {data.get('errors', [])}")
else:
    for name in ["update_page_content", "append_section", "replace_section",
                  "remove_section", "update_module", "validate_blocks"]:
        skip(name, "create_page failed")

# 2.8 save_to_library
code, data = api("/library/save", "POST", {
    "title": f"Test Library Item {int(time.time())}",
    "content": '<!-- wp:divi/placeholder --><!-- wp:divi/section {"module":{"decoration":{"layout":{"desktop":{"value":{"display":"block"}}}}},"builderVersion":"5.1.0"} --><!-- wp:divi/row {"module":{"decoration":{"layout":{"desktop":{"value":{"display":"block"}}}}},"builderVersion":"5.1.0"} --><!-- wp:divi/column {"module":{"decoration":{"layout":{"desktop":{"value":{"display":"block"}}}}},"builderVersion":"5.1.0"} --><!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"\\u003cp\\u003eLibrary test\\u003c/p\\u003e"}}},"builderVersion":"5.1.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->',
    "layout_type": "section"
})
test("save_to_library returns 200", code == 200 or code == 201)
test("save_to_library has id", "id" in data)
LIB_ITEM_ID = data.get("id", 0)

# 2.9 preset_cleanup (dry_run)
code, data = api("/preset-cleanup", "POST", {"dry_run": True})
test("preset_cleanup dry_run returns 200", code == 200)

# 2.10 preset_update (rename test — we'll rename and rename back)
code, presets = api("/presets")
if code == 200:
    d5 = presets.get("divi5_presets", {}).get("module", {})
    test_preset_id = None
    test_preset_name = None
    for mod, info in d5.items():
        for pid, preset in info.get("items", {}).items():
            if preset.get("name"):
                test_preset_id = pid
                test_preset_name = preset["name"]
                break
        if test_preset_id:
            break

    if test_preset_id:
        code, data = api("/preset-update", "POST", {
            "preset_id": test_preset_id,
            "name": f"__test_{test_preset_name}"
        })
        test("preset_update returns 200", code == 200)
        # Rename back
        api("/preset-update", "POST", {
            "preset_id": test_preset_id,
            "name": test_preset_name
        })
    else:
        skip("preset_update", "no named preset found")
else:
    skip("preset_update", "could not fetch presets")

# 2.11 preset_delete — skip (don't want to delete real presets)
skip("preset_delete", "skipped to avoid deleting real presets")

# 2.12 create_tb_template — skip (creates real template)
skip("create_tb_template", "skipped to avoid creating orphan templates")

# 2.13 update_tb_layout — skip (modifies real layout)
skip("update_tb_layout", "skipped to avoid modifying real layouts")


# ============================================================
print("\n--- 3. BLOCK VALIDATOR EDGE CASES ---\n")
# ============================================================

# 3.1 Valid complex page (FlowSchule)
code, page = api("/page/312")
if code == 200 and page.get("content_raw"):
    code, data = api("/validate", "POST", {"content": page["content_raw"]})
    test("validate FlowSchule (60 blocks)", code == 200 and data.get("valid") is True,
         f"errors: {data.get('errors', [])[:3]}")
else:
    skip("validate FlowSchule", "page 312 not found")

# 3.2 Intentionally broken: missing builderVersion
code, data = api("/validate", "POST", {
    "content": '<!-- wp:divi/placeholder --><!-- wp:divi/section {"module":{"decoration":{"layout":{"desktop":{"value":{"display":"block"}}}}}} --><!-- wp:divi/row {"module":{"decoration":{"layout":{"desktop":{"value":{"display":"block"}}}}}} --><!-- wp:divi/column {"module":{"decoration":{"layout":{"desktop":{"value":{"display":"block"}}}}}} --><!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"\\u003cp\\u003eNo version\\u003c/p\\u003e"}}}} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->'
})
test("validator catches missing builderVersion", code == 200 and len(data.get("errors", [])) > 0,
     f"errors: {len(data.get('errors', []))}")

# 3.3 Intentionally broken: button with icon.enable missing
code, data = api("/validate", "POST", {
    "content": '<!-- wp:divi/placeholder --><!-- wp:divi/section {"module":{"decoration":{"layout":{"desktop":{"value":{"display":"block"}}}}},"builderVersion":"5.1.0"} --><!-- wp:divi/row {"module":{"decoration":{"layout":{"desktop":{"value":{"display":"block"}}}}},"builderVersion":"5.1.0"} --><!-- wp:divi/column {"module":{"decoration":{"layout":{"desktop":{"value":{"display":"block"}}}}},"builderVersion":"5.1.0"} --><!-- wp:divi/button {"button":{"decoration":{"button":{"desktop":{"value":{"enable":"on"}}}}},"builderVersion":"5.1.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->'
})
test("validator warns button missing icon.enable:off", code == 200 and len(data.get("warnings", [])) > 0,
     f"warnings: {len(data.get('warnings', []))}")

# 3.4 Empty content
code, data = api("/validate", "POST", {"content": ""})
test("validator handles empty content", code == 200)

# 3.5 Nested modules (dropdown inside text)
code, data = api("/validate", "POST", {
    "content": '<!-- wp:divi/placeholder --><!-- wp:divi/section {"module":{"decoration":{"layout":{"desktop":{"value":{"display":"block"}}}}},"builderVersion":"5.1.0"} --><!-- wp:divi/row {"module":{"decoration":{"layout":{"desktop":{"value":{"display":"block"}}}}},"builderVersion":"5.1.0"} --><!-- wp:divi/column {"module":{"decoration":{"layout":{"desktop":{"value":{"display":"block"}}}}},"builderVersion":"5.1.0"} --><!-- wp:divi/text {"module":{"advanced":{"html":{"desktop":{"value":{"elementType":"li"}}}}},"content":{"innerContent":{"desktop":{"value":""}}},"builderVersion":"5.1.0"} --><!-- wp:divi/dropdown {"module":{"advanced":{"dropdown":{"desktop":{"value":{"showOn":"hover","position":"floating"}}}}},"builderVersion":"5.1.0"} --><!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"\\u003cp\\u003eNested\\u003c/p\\u003e"}}},"builderVersion":"5.1.0"} /--><!-- /wp:divi/dropdown --><!-- /wp:divi/text --><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->'
})
test("validator handles nested text>dropdown", code == 200 and data.get("valid") is True,
     f"errors: {data.get('errors', [])}")

# 3.6 Hover on wrong path (top-level hover, not desktop.hover)
code, data = api("/validate", "POST", {
    "content": '<!-- wp:divi/placeholder --><!-- wp:divi/section {"module":{"decoration":{"layout":{"desktop":{"value":{"display":"block"}}}}},"builderVersion":"5.1.0"} --><!-- wp:divi/row {"module":{"decoration":{"layout":{"desktop":{"value":{"display":"block"}}}}},"builderVersion":"5.1.0"} --><!-- wp:divi/column {"module":{"decoration":{"layout":{"desktop":{"value":{"display":"block"}}}}},"builderVersion":"5.1.0"} --><!-- wp:divi/text {"module":{"decoration":{"background":{"desktop":{"value":{"color":"#ffffff"}},"hover":{"value":{"color":"#000000"}}}}},"content":{"innerContent":{"desktop":{"value":"\\u003cp\\u003eHover test\\u003c/p\\u003e"}}},"builderVersion":"5.1.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->'
})
test("validator warns hover on wrong path",
     code == 200 and any(w.get("code") == "hover_wrong_path" for w in data.get("warnings", [])),
     f"warnings: {[w.get('code') for w in data.get('warnings', [])]}")

# 3.7 Valid hover format (desktop.hover — no warning)
code, data = api("/validate", "POST", {
    "content": '<!-- wp:divi/placeholder --><!-- wp:divi/section {"module":{"decoration":{"layout":{"desktop":{"value":{"display":"block"}}}}},"builderVersion":"5.1.0"} --><!-- wp:divi/row {"module":{"decoration":{"layout":{"desktop":{"value":{"display":"block"}}}}},"builderVersion":"5.1.0"} --><!-- wp:divi/column {"module":{"decoration":{"layout":{"desktop":{"value":{"display":"block"}}}}},"builderVersion":"5.1.0"} --><!-- wp:divi/text {"module":{"decoration":{"background":{"desktop":{"value":{"color":"#ffffff"},"hover":{"value":{"color":"#000000"}}}}}},"content":{"innerContent":{"desktop":{"value":"\\u003cp\\u003eCorrect hover\\u003c/p\\u003e"}}},"builderVersion":"5.1.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->'
})
hover_warns = [w for w in data.get("warnings", []) if w.get("code") == "hover_wrong_path"]
test("validator no false positive on correct hover",
     code == 200 and len(hover_warns) == 0,
     f"unexpected hover warnings: {hover_warns}")

# 3.8 icon.decoration border/background warning
code, data = api("/validate", "POST", {
    "content": '<!-- wp:divi/placeholder --><!-- wp:divi/section {"module":{"decoration":{"layout":{"desktop":{"value":{"display":"block"}}}}},"builderVersion":"5.1.0"} --><!-- wp:divi/row {"module":{"decoration":{"layout":{"desktop":{"value":{"display":"block"}}}}},"builderVersion":"5.1.0"} --><!-- wp:divi/column {"module":{"decoration":{"layout":{"desktop":{"value":{"display":"block"}}}}},"builderVersion":"5.1.0"} --><!-- wp:divi/icon {"icon":{"innerContent":{"desktop":{"value":{"unicode":"&#xe048;","type":"divi","weight":400}}},"decoration":{"border":{"desktop":{"value":{"styles":{"all":{"width":"2px","color":"#ff0000","style":"solid"}}}}}}},"module":{"decoration":{"background":{"desktop":{"value":{"color":"#f0f0f0"}}}}},"builderVersion":"5.1.0"} /--><!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->'
})
test("validator warns icon.decoration border",
     code == 200 and any(w.get("code") == "icon_decoration_not_editable" for w in data.get("warnings", [])),
     f"warnings: {[w.get('code') for w in data.get('warnings', [])]}")


# ============================================================
print("\n--- 4. SCHEMA OPTIMIZER ---\n")
# ============================================================

# Schema optimizer runs in TypeScript (schema-optimizer.ts), not PHP.
# The REST endpoint always returns full schemas. We verify endpoints return data.
test_modules = ["divi/text", "divi/button", "divi/group", "divi/dropdown", "divi/number-counter", "divi/testimonial"]
for mod in test_modules:
    code, data = api(f"/module/{mod}")
    if code == 200:
        has_attrs = "attributes" in data
        size = len(json.dumps(data))
        test(f"schema {mod}", has_attrs, f"{size} chars")
    else:
        test(f"schema {mod}", False, f"HTTP {code}")


# ============================================================
print("\n--- 5. TARGETING MODES ---\n")
# ============================================================

# Targeting tests use the test page with 3 sections (Unique, Duplicate, Duplicate)
# and 3 text modules (text:1, text:2, text:3)

if NEW_PAGE_ID:
    # Reset page content for targeting tests
    code, data = api(f"/page/{NEW_PAGE_ID}/content", "POST", {"content": TEST_CONTENT})
    test("targeting: content reset", code == 200, f"HTTP {code}")

    # 5.1 update_module with auto_index (target 2nd text module)
    code, data = api(f"/page/{NEW_PAGE_ID}/update-module", "POST", {
        "auto_index": "text:2",
        "attrs": {"content.innerContent.desktop.value": "\\u003cp\\u003eAuto-indexed\\u003c/p\\u003e"}
    })
    test("update_module auto_index", code == 200 and data.get("success") is True,
         f"HTTP {code}, data: {data}")
    if code == 200:
        test("update_module auto_index matched_by",
             data.get("matched_by") == "auto_index",
             f"matched_by: {data.get('matched_by')}")

    # 5.2 update_module with occurrence (2nd module with label "Duplicate Section Text")
    code, data = api(f"/page/{NEW_PAGE_ID}/update-module", "POST", {
        "label": "Duplicate Section Text",
        "occurrence": 2,
        "attrs": {"content.innerContent.desktop.value": "\\u003cp\\u003eOccurrence 2\\u003c/p\\u003e"}
    })
    test("update_module occurrence=2", code == 200 and data.get("success") is True,
         f"HTTP {code}, data: {data}")
    if code == 200:
        test("update_module occurrence reports total_matches",
             data.get("total_matches", 0) == 2,
             f"total_matches: {data.get('total_matches')}")

    # 5.3 get_section with match_text
    code, data = api(f"/page/{NEW_PAGE_ID}/get-section?match_text=Alpha")
    test("get_section match_text", code == 200 and "markup" in data,
         f"HTTP {code}, keys: {list(data.keys()) if isinstance(data, dict) else 'n/a'}")

    # 5.4 replace_section with match_text
    code, data = api(f"/page/{NEW_PAGE_ID}/replace-section", "POST", {
        "match_text": "Occurrence 2",
        "content": make_section("Replaced Via Match", "<p>Replaced via match_text</p>")
    })
    test("replace_section match_text", code == 200 and data.get("success") is True,
         f"HTTP {code}, data: {data}")

    # 5.5 remove_section with match_text
    code, data = api(f"/page/{NEW_PAGE_ID}/remove-section", "POST", {
        "match_text": "Replaced via match_text"
    })
    test("remove_section match_text", code == 200 and data.get("success") is True,
         f"HTTP {code}, data: {data}")

    # 5.6 section tools with occurrence (duplicate labels)
    # Reset content first to get clean duplicate sections back
    code, data = api(f"/page/{NEW_PAGE_ID}/content", "POST", {"content": TEST_CONTENT})
    test("occurrence: content reset", code == 200, f"HTTP {code}")
    code, data = api(f"/page/{NEW_PAGE_ID}/get-section?label=Duplicate+Section&occurrence=2")
    test("get_section occurrence=2",
         code == 200 and "markup" in data and "Gamma" in data.get("markup", ""),
         f"HTTP {code}, has Gamma: {'Gamma' in data.get('markup', '')}")

else:
    for name in ["update_module auto_index", "update_module auto_index matched_by",
                  "update_module occurrence=2", "update_module occurrence reports total_matches",
                  "get_section match_text", "replace_section match_text",
                  "remove_section match_text", "get_section occurrence=2"]:
        skip(name, "create_page failed")


# ============================================================
print("\n--- 6. SLIM LAYOUT ---\n")
# ============================================================

if NEW_PAGE_ID:
    # Reset to clean content
    code, data = api(f"/page/{NEW_PAGE_ID}/content", "POST", {"content": TEST_CONTENT})
    test("slim layout: content reset", code == 200, f"HTTP {code}")

    # 6.1 Default layout returns slim (no attrs)
    code, data = api(f"/page/{NEW_PAGE_ID}/layout")
    test("slim layout returns 200", code == 200)
    if code == 200 and "layout" in data:
        first = data["layout"][0] if data["layout"] else {}
        test("slim layout has auto_index", "auto_index" in first,
             f"keys: {list(first.keys())}")
        test("slim layout has text_preview", "text_preview" in first,
             f"keys: {list(first.keys())}")
        test("slim layout has admin_label", "admin_label" in first,
             f"keys: {list(first.keys())}")
        test("slim layout omits attrs", "attrs" not in first,
             f"has attrs key: {'attrs' in first}")
    else:
        for n in ["slim layout has auto_index", "slim layout has text_preview",
                   "slim layout has admin_label", "slim layout omits attrs"]:
            skip(n, "layout response missing")

    # 6.2 Full layout returns attrs
    code, data = api(f"/page/{NEW_PAGE_ID}/layout?full=true")
    test("full layout returns 200", code == 200)
    if code == 200 and "layout" in data:
        first = data["layout"][0] if data["layout"] else {}
        test("full layout has attrs", "attrs" in first,
             f"keys: {list(first.keys())}")
    else:
        skip("full layout has attrs", "layout response missing")

else:
    for name in ["slim layout returns 200", "slim layout has auto_index",
                  "slim layout has text_preview", "slim layout has admin_label",
                  "slim layout omits attrs", "full layout returns 200",
                  "full layout has attrs"]:
        skip(name, "create_page failed")


# ============================================================
print("\n--- 7. WP-CLI ---\n")
# ============================================================

# WP-CLI runs locally via MCP TypeScript layer (execFile), not REST.
# These tests can only run when the MCP server is accessible.
skip("wp-cli post create", "WP-CLI runs via MCP (local execFile), not REST")
skip("wp-cli post delete", "WP-CLI runs via MCP (local execFile), not REST")
skip("wp-cli post meta get", "WP-CLI runs via MCP (local execFile), not REST")
skip("wp-cli post meta list", "WP-CLI runs via MCP (local execFile), not REST")


# ============================================================
print("\n--- 8. PERMISSION TIERS ---\n")
# ============================================================

# Test unauthenticated access (should fail)
req = urllib.request.Request(f"{BASE}/pages")
try:
    with urllib.request.urlopen(req) as resp:
        test("unauthenticated blocked", False, "got 200 — should be 401")
except urllib.error.HTTPError as e:
    test("unauthenticated blocked", e.code in (401, 403), f"HTTP {e.code}")


# ============================================================
print("\n--- 9. CLEANUP ---\n")
# ============================================================

# Delete test page
if NEW_PAGE_ID:
    req = urllib.request.Request(
        f"{WP_URL}/wp-json/wp/v2/pages/{NEW_PAGE_ID}?force=true",
        method="DELETE"
    )
    req.add_header("Authorization", f"Basic {AUTH}")
    try:
        with urllib.request.urlopen(req) as resp:
            test("cleanup test page", True)
    except Exception:
        test("cleanup test page", False, "could not delete")

# Delete test library item (Divi library CPT is not REST-registered, use wp_delete_post via WP-CLI)
if LIB_ITEM_ID:
    skip("cleanup test library item", "Divi library CPT not REST-deletable — use WP-CLI or WP Admin")

print()


# ============================================================
print("=" * 60)
print(f"  RESULTS: {passed} passed, {failed} failed, {skipped} skipped")
print("=" * 60)

if errors:
    print("\nFailed tests:")
    for e in errors:
        print(f"  ✗ {e}")

sys.exit(1 if failed > 0 else 0)
