/**
 * Schema Optimizer
 *
 * Strips CSS selectors, React component metadata, and VB UI hints from
 * Divi module schemas. Reduces token usage by ~70% while preserving all
 * content-relevant attribute information (attrName, label, description,
 * settings hierarchy).
 */

const STRIP_KEYS = new Set([
  'component',              // React component type/name/props
  'selector',               // CSS selector templates
  'customPostTypeSelector', // CPT-specific selectors
  'styleProps',             // CSS property → selector mappings
  'elementType',            // HTML element type hint (redundant with module.advanced.html)
  'render',                 // Whether VB renders this field
  'priority',               // Field render order in VB
  'groupSlug',              // VB settings panel group
  'category',               // Field category in VB (not block category)
  'features',               // Hover/sticky/preset feature flags
  'subName',                // Sub-field identifier (redundant with structure)
  'className',              // WordPress block attribute — Divi 5 ignores it (use module.decoration.attributes)
]);

type JsonValue = string | number | boolean | null | JsonValue[] | { [key: string]: JsonValue };

/**
 * Recursively strip keys and remove empty containers.
 */
function stripAndClean(value: JsonValue): JsonValue | undefined {
  if (value === null || typeof value !== 'object') {
    return value;
  }

  if (Array.isArray(value)) {
    const cleaned = value
      .map((item) => stripAndClean(item))
      .filter((item): item is JsonValue => item != null);
    return cleaned.length > 0 ? cleaned : undefined;
  }

  const result: Record<string, JsonValue> = {};
  for (const [key, val] of Object.entries(value)) {
    if (STRIP_KEYS.has(key)) {
      continue;
    }
    const cleaned = stripAndClean(val as JsonValue);
    if (cleaned != null) {
      result[key] = cleaned;
    }
  }

  return Object.keys(result).length > 0 ? result : undefined;
}

/**
 * Optimize a module schema response for AI content generation.
 * Strips ~70% of tokens from the `attributes` object (CSS selectors,
 * React components, VB UI metadata). Top-level fields (name, title,
 * category, description, supports) are preserved as-is — they are
 * small and content-relevant.
 */
export function optimizeSchema(schema: Record<string, any>): Record<string, any> {
  const { attributes, ...rest } = schema;

  if (!attributes) {
    return schema;
  }

  const optimized = stripAndClean(attributes) as Record<string, JsonValue> | undefined;

  return {
    ...rest,
    attributes: optimized ?? {},
  };
}
