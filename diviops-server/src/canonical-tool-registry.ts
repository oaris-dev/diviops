/**
 * Transport-neutral ownership of DiviOps MCP registrations.
 *
 * The shipped server still materializes this registry into the v1 SDK. The
 * non-publishing v2 compatibility spike materializes the same definitions
 * into the split v2 server package. Keeping the registry ignorant of either
 * SDK is the guard against a second tool catalog or a wire-shape translator.
 */

// The SDKs deliberately expose overloaded, schema-driven registration APIs.
// This seam stores those values without trying to restate either SDK's
// generics; type safety remains at each canonical registration callsite.
/* eslint-disable @typescript-eslint/no-explicit-any */
export type ToolRegistrar = {
  registerTool(name: string, config: any, handler: any): unknown;
};

export type ResourceRegistrar = {
  registerResource(
    name: string,
    uri: string,
    config: any,
    handler: any,
  ): unknown;
};

export type RegistryTarget = ToolRegistrar & ResourceRegistrar;

type ToolRegistration = {
  name: string;
  config: any;
  handler: any;
};

type ResourceRegistration = {
  name: string;
  uri: string;
  config: any;
  handler: any;
};

export class CanonicalToolRegistry implements RegistryTarget {
  readonly #tools = new Map<string, ToolRegistration>();
  readonly #resources = new Map<string, ResourceRegistration>();

  registerTool(name: string, config: any, handler: any): void {
    if (this.#tools.has(name)) {
      throw new Error(`Duplicate canonical MCP tool registration: ${name}`);
    }
    this.#tools.set(name, { name, config, handler });
  }

  registerResource(name: string, uri: string, config: any, handler: any): void {
    if (this.#resources.has(name)) {
      throw new Error(`Duplicate canonical MCP resource registration: ${name}`);
    }
    this.#resources.set(name, { name, uri, config, handler });
  }

  install(target: RegistryTarget): void {
    for (const registration of this.#tools.values()) {
      target.registerTool(
        registration.name,
        registration.config,
        registration.handler,
      );
    }
    for (const registration of this.#resources.values()) {
      target.registerResource(
        registration.name,
        registration.uri,
        registration.config,
        registration.handler,
      );
    }
  }
}
/* eslint-enable @typescript-eslint/no-explicit-any */
