/**
 * WordPress REST API client with Application Password authentication.
 *
 * Uses WP Application Passwords (built into WP 5.6+) for auth.
 * Generate one at: WP Admin → Users → Your Profile → Application Passwords.
 */

import {
  MIN_PLUGIN_VERSION,
  compareVersions,
  type HandshakeResult,
} from './compatibility.js';

export interface WPClientConfig {
  siteUrl: string;
  username: string;
  applicationPassword: string;
}

export class WPClient {
  private baseUrl: string;
  private authHeader: string;

  constructor(config: WPClientConfig) {
    // Strip trailing slash.
    this.baseUrl = config.siteUrl.replace(/\/+$/, '');

    // WP Application Passwords use Basic Auth.
    const credentials = Buffer.from(
      `${config.username}:${config.applicationPassword}`
    ).toString('base64');
    this.authHeader = `Basic ${credentials}`;
  }

  /**
   * Make a request to the diviops/v1 REST namespace.
   */
  async request<T = unknown>(
    endpoint: string,
    options: {
      method?: string;
      body?: Record<string, unknown>;
      params?: Record<string, string>;
    } = {}
  ): Promise<T> {
    const { method = 'GET', body, params } = options;

    let url = `${this.baseUrl}/wp-json/diviops/v1${endpoint}`;

    if (params) {
      const searchParams = new URLSearchParams(params);
      url += `?${searchParams.toString()}`;
    }

    const fetchOptions: RequestInit = {
      method,
      headers: {
        Authorization: this.authHeader,
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
    };

    if (body && method !== 'GET') {
      fetchOptions.body = JSON.stringify(body);
    }

    const response = await fetch(url, fetchOptions);

    if (!response.ok) {
      const errorBody = await response.text();
      let errorMessage: string;
      try {
        const errorJson = JSON.parse(errorBody);
        errorMessage = errorJson.message || errorBody;
      } catch {
        errorMessage = errorBody;
      }

      if (response.status === 429) {
        const retryAfter = response.headers.get('Retry-After') || '60';
        throw new Error(
          `Rate limited: ${errorMessage} (retry after ${retryAfter}s)`
        );
      }

      throw new Error(
        `WordPress API error (${response.status}): ${errorMessage}`
      );
    }

    return response.json() as Promise<T>;
  }

  /**
   * Test the connection to WordPress.
   */
  async testConnection(): Promise<{ ok: boolean; message: string }> {
    try {
      const result = await this.request<{ builder: { version: string } }>(
        '/settings'
      );
      return {
        ok: true,
        message: `Connected to Divi ${result.builder?.version ?? 'unknown'}`,
      };
    } catch (error) {
      return {
        ok: false,
        message: `Connection failed: ${error instanceof Error ? error.message : String(error)}`,
      };
    }
  }

  /**
   * Perform version handshake with the WP plugin.
   *
   * Verifies that the plugin version is compatible with this server.
   * Throws on:
   * - Network errors or any non-2xx HTTP response (401/403/426/503).
   * - Plugin version below {@link MIN_PLUGIN_VERSION}.
   */
  async handshake(
    serverVersion: string,
  ): Promise<HandshakeResult> {
    const result = await this.request<HandshakeResult>('/handshake', {
      method: 'POST',
      body: { mcp_server_version: serverVersion },
    });

    // Server-side check passed — now verify plugin meets our minimum.
    if (compareVersions(result.plugin_version, MIN_PLUGIN_VERSION) < 0) {
      throw new Error(
        `WP plugin version ${result.plugin_version} is below the minimum required ${MIN_PLUGIN_VERSION}. ` +
          'Please update the diviops-agent plugin.',
      );
    }

    return result;
  }
}
