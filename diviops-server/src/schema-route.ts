export function normalizeSchemaModuleName(moduleName: string): string {
  const trimmed = moduleName.trim();
  return trimmed.startsWith("divi/") ? trimmed.slice("divi/".length) : trimmed;
}

export function schemaModuleRoute(moduleName: string): string | null {
  const name = moduleName.trim();
  if (!/^[a-zA-Z0-9_-]+(?:\/[a-zA-Z0-9_-]+)?$/.test(name)) return null;
  const normalized = normalizeSchemaModuleName(name);
  // This literal route is reserved for the build-time dump, never a single module.
  if (normalized.toLowerCase() === "dump-all") return null;
  return `/schema/module/${normalized}`;
}
