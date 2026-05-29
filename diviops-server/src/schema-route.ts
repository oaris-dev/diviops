export function normalizeSchemaModuleName(moduleName: string): string {
  const trimmed = moduleName.trim();
  return trimmed.startsWith("divi/") ? trimmed.slice("divi/".length) : trimmed;
}

export function schemaModuleRoute(moduleName: string): string {
  return `/schema/module/${encodeURIComponent(normalizeSchemaModuleName(moduleName))}`;
}
