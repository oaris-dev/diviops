import { createHash } from "node:crypto";
import {
  preflightCrossEnvHeaderSync,
  type CrossEnvVerdict,
  type GcidReference,
  type ModulePresetReference,
  type OffCanvasReference,
  type PreflightIssue,
  type Sha256Checksum,
  type SourceLayoutPayload,
  type TargetLayoutContext,
  type AttachmentReference,
  type UrlOccurrence,
} from "./header-preflight.js";

export const THEME_BUILDER_LAYOUT_KIND_POST_TYPES = {
  tb_header_layout: "et_header_layout",
  tb_footer_layout: "et_footer_layout",
} as const;

export type CrossEnvThemeBuilderLayoutKind = keyof typeof THEME_BUILDER_LAYOUT_KIND_POST_TYPES;

export const THEME_BUILDER_LAYOUT_VALIDATOR_VERSION =
  "diviops.cross_env.theme_builder_layout.validator.v1" as const;

export interface CrossEnvThemeBuilderLayoutPreflightInput {
  source: SourceLayoutPayload;
  target: TargetLayoutContext;
}
export interface CrossEnvThemeBuilderLayoutPreflightReport {
  type: "cross_env_theme_builder_layout_preflight";
  dry_run: true;
  verdict: CrossEnvVerdict;
  validator_contract: {
    version: typeof THEME_BUILDER_LAYOUT_VALIDATOR_VERSION;
    serialization: "wordpress_divi_full_content.v1";
  };
  source: {
    origin: string;
    object_kind: string;
    object_id?: number | string;
    object_title?: string;
    object_post_type?: string;
    checksum: {
      algorithm: "sha256";
      provided?: string;
      computed: string;
      matches: boolean | null;
    };
  };
  target: {
    origin: string;
    destination_kind: string;
    destination_id?: number | string;
    destination_title?: string;
    destination_post_type?: string;
    cache_scope?: string;
    destination_checksum?: Sha256Checksum;
    template_linkage?: unknown;
    template_linkage_digest?: Sha256Checksum;
  };
  findings: {
    urls: { by_host: Record<string, string[]>; occurrences: UrlOccurrence[] };
    attachments: AttachmentReference[];
    global_colors: GcidReference[];
    module_presets: ModulePresetReference[];
    off_canvas: OffCanvasReference[];
  };
  source_origin_rule: {
    normalized_origin: string;
    rewrite_scope: "same_origin_wp_uploads_only";
  };
  rewrite_plan: {
    url_rewrites: Array<{ from: string; to: string }>;
    attachment_replacements: Array<{
      from_source_id: number;
      to_target_id: number;
      proof?: string;
    }>;
    third_party_urls_kept: string[];
    missing_attachment_remaps: number[];
  };
  cache_plan: {
    scope: string;
    steps: Array<{ kind: string; target: string; note: string }>;
  };
  blockers: PreflightIssue[];
  operator_actions: PreflightIssue[];
  notes: string[];
  confirmation_binding: {
    schema: "diviops.cross_env.theme_builder_layout_preflight.confirmation_binding.v1";
    algorithm: "sha256";
    fingerprint: string;
    input: Record<string, unknown>;
  };
}

function sha256(value: string): string {
  return createHash("sha256").update(value).digest("hex");
}

function canonicalize(value: unknown): unknown {
  if (Array.isArray(value)) return value.map(canonicalize);
  if (!value || typeof value !== "object") return value;
  return Object.fromEntries(
    Object.entries(value as Record<string, unknown>)
      .filter(([, nested]) => nested !== undefined)
      .sort(([a], [b]) => a.localeCompare(b))
      .map(([key, nested]) => [key, canonicalize(nested)]),
  );
}

function canonicalJson(value: unknown): string {
  return JSON.stringify(canonicalize(value));
}

function isSha256Digest(value: unknown): value is Sha256Checksum {
  if (!value || typeof value !== "object") return false;
  const record = value as Record<string, unknown>;
  return (
    record.algorithm === "sha256" &&
    typeof record.computed === "string" &&
    /^[a-f0-9]{64}$/i.test(record.computed)
  );
}

function stableIssues(issues: PreflightIssue[]): Array<Record<string, unknown>> {
  return issues
    .map((issue) => ({ code: issue.code, detail: issue.detail ?? null }))
    .sort((a, b) => canonicalJson(a).localeCompare(canonicalJson(b)));
}

function expectedPostType(kind: string): string | undefined {
  return THEME_BUILDER_LAYOUT_KIND_POST_TYPES[
    kind as CrossEnvThemeBuilderLayoutKind
  ];
}

function replaceCacheDestinationKind(
  target: string,
  kind: string,
): string {
  return target.replace(/^tb_header_layout(?=#|$)/, kind);
}

export function preflightCrossEnvThemeBuilderLayoutSync(
  input: CrossEnvThemeBuilderLayoutPreflightInput,
): CrossEnvThemeBuilderLayoutPreflightReport {
  // The shipped header-v1 engine remains the immutable compatibility analyzer.
  // The generic contract reuses its hazard inventory while owning a distinct
  // report and fingerprint namespace.
  const base = preflightCrossEnvHeaderSync({
    source: { ...input.source, object_kind: "tb_header_layout" },
    target: { ...input.target, destination_kind: "tb_header_layout" },
  });

  const blockers = [...base.blockers];
  const operatorActions = [...base.operator_actions];
  const sourceKind = String(input.source.object_kind ?? "");
  const targetKind = String(input.target.destination_kind ?? "");
  const sourceExpectedType = expectedPostType(sourceKind);
  const targetExpectedType = expectedPostType(targetKind);

  if (!sourceExpectedType) {
    blockers.push({
      code: "unsupported_source_kind",
      message: "Only tb_header_layout and tb_footer_layout sources are supported.",
      detail: { received: sourceKind },
    });
  }
  if (!targetExpectedType) {
    blockers.push({
      code: "unsupported_destination_kind",
      message: "Only tb_header_layout and tb_footer_layout destinations are supported.",
      detail: { received: targetKind },
    });
  }
  if (sourceExpectedType && targetExpectedType && sourceKind !== targetKind) {
    blockers.push({
      code: "layout_kind_mismatch",
      message: "Source and target Theme Builder layout kinds must match.",
      detail: { source_kind: sourceKind, destination_kind: targetKind },
    });
  }
  if (sourceExpectedType && input.source.object_post_type !== sourceExpectedType) {
    blockers.push({
      code: "source_post_type_mismatch",
      message: "Source post type does not match its declared layout kind.",
      detail: {
        kind: sourceKind,
        expected: sourceExpectedType,
        actual: input.source.object_post_type ?? null,
      },
    });
  }
  if (targetExpectedType && input.target.destination_post_type !== targetExpectedType) {
    blockers.push({
      code: "destination_post_type_mismatch",
      message: "Target post type does not match its declared layout kind.",
      detail: {
        kind: targetKind,
        expected: targetExpectedType,
        actual: input.target.destination_post_type ?? null,
      },
    });
  }
  if (!isSha256Digest(input.target.template_linkage_digest)) {
    operatorActions.push({
      code: "missing_template_linkage_digest",
      message: "Provide the canonical target template-linkage digest before apply confirmation.",
    });
  }

  const cachePlan = {
    ...base.cache_plan,
    steps: base.cache_plan.steps.map((step) => ({
      ...step,
      target: replaceCacheDestinationKind(step.target, targetKind),
    })),
  };
  const sourceOriginRule = {
    normalized_origin: base.source.origin,
    rewrite_scope: "same_origin_wp_uploads_only" as const,
  };
  const validatorContract = {
    version: THEME_BUILDER_LAYOUT_VALIDATOR_VERSION,
    serialization: "wordpress_divi_full_content.v1" as const,
  };
  const verdict: CrossEnvVerdict =
    blockers.length > 0
      ? "refused"
      : operatorActions.length > 0
        ? "operator_action_required"
        : "safe_dry_run";

  const reportWithoutBinding = {
    type: "cross_env_theme_builder_layout_preflight" as const,
    dry_run: true as const,
    verdict,
    validator_contract: validatorContract,
    source: {
      ...base.source,
      object_kind: sourceKind,
      object_post_type: input.source.object_post_type,
    },
    target: {
      ...base.target,
      destination_kind: targetKind,
      destination_post_type: input.target.destination_post_type,
      template_linkage: input.target.template_linkage,
      template_linkage_digest: input.target.template_linkage_digest,
    },
    findings: base.findings,
    source_origin_rule: sourceOriginRule,
    rewrite_plan: base.rewrite_plan,
    cache_plan: cachePlan,
    blockers,
    operator_actions: operatorActions,
    notes: [
      "Preflight only: Free/core evidence and CLI do not mutate WordPress.",
      "The generic binding is distinct from the shipped header-v1 fingerprint.",
      "Template linkage is drift evidence only and never authorizes assignment mutation.",
    ],
  };

  const bindingInput = {
    source: {
      origin: reportWithoutBinding.source.origin,
      object_kind: sourceKind,
      object_id: reportWithoutBinding.source.object_id,
      object_post_type: input.source.object_post_type,
      checksum: {
        algorithm: "sha256",
        computed: reportWithoutBinding.source.checksum.computed,
      },
    },
    target: {
      origin: reportWithoutBinding.target.origin,
      destination_kind: targetKind,
      destination_id: reportWithoutBinding.target.destination_id,
      destination_post_type: input.target.destination_post_type,
      destination_checksum: reportWithoutBinding.target.destination_checksum ?? null,
      template_linkage_digest: input.target.template_linkage_digest ?? null,
      cache_scope: reportWithoutBinding.target.cache_scope ?? null,
    },
    source_origin_rule: sourceOriginRule,
    rewrite_plan: reportWithoutBinding.rewrite_plan,
    cache_plan: cachePlan,
    dependency_evidence: {
      attachment_remap_proof: reportWithoutBinding.findings.attachments,
      global_color_resolution: reportWithoutBinding.findings.global_colors,
      module_preset_resolution: reportWithoutBinding.findings.module_presets,
      off_canvas: {
        refused: reportWithoutBinding.findings.off_canvas.length > 0,
        refs: reportWithoutBinding.findings.off_canvas,
      },
    },
    blockers: stableIssues(blockers),
    operator_actions: stableIssues(operatorActions),
    validator_contract: validatorContract,
  };
  const schema =
    "diviops.cross_env.theme_builder_layout_preflight.confirmation_binding.v1" as const;
  const canonicalInput = canonicalize(bindingInput) as Record<string, unknown>;

  return {
    ...reportWithoutBinding,
    confirmation_binding: {
      schema,
      algorithm: "sha256",
      fingerprint: sha256(canonicalJson({ schema, input: canonicalInput })),
      input: canonicalInput,
    },
  };
}
