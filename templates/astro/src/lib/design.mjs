export const DESIGN_SCHEMA_VERSION = 1;
export const presets = Object.freeze({ palette: ['paper', 'night', 'lilac'], typography: ['editorial', 'modern', 'classic'], layout: ['journal', 'magazine', 'minimal'] });
export function validateDesign(value) {
  const fields = new Set(['version', 'schema_version', 'palette', 'typography', 'layout', 'site_title', 'description', 'logo_media_id', 'navigation']);
  if (value && Object.keys(value).some(key => !fields.has(key))) throw new Error('Unknown design setting');
  if (!value || !Number.isInteger(value.version) || value.version < 0) throw new Error('Design requires a nonnegative revision');
  if (value.schema_version !== undefined && value.schema_version !== DESIGN_SCHEMA_VERSION) throw new Error('Unsupported design schema');
  for (const [key, allowed] of Object.entries(presets)) if (!allowed.includes(value[key])) throw new Error(`Unsupported design ${key}`);
  for (const [key, max] of [['site_title', 120], ['description', 300]]) if (typeof value[key] !== 'string' || value[key].length > max) throw new Error(`Invalid design ${key}`);
  if (!Number.isInteger(value.logo_media_id) || value.logo_media_id < 0) throw new Error('Invalid logo');
  if (!Array.isArray(value.navigation) || value.navigation.length > 20 || value.navigation.some(item => !Number.isInteger(item.page_id) || item.page_id < 1 || typeof item.label !== 'string' || item.label.length > 120)) throw new Error('Invalid navigation');
  return { ...value, schema_version: DESIGN_SCHEMA_VERSION };
}
