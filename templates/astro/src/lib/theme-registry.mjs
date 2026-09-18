import themes from './themes.json' with { type: 'json' };

/**
 * The small, boring contract every theme must satisfy. A theme is data first;
 * templates are shared unless a theme explicitly opts into a named edition.
 */
export const THEME_TEMPLATES = Object.freeze(['standard', 'bulletin']);
export const THEME_ROUTE_STATES = Object.freeze(['home', 'archive', 'article', 'search', 'taxonomy', 'rss', '404']);
export const THEME_STRUCTURE_KEYS = Object.freeze(['header', 'hero', 'cards', 'reading', 'mobile']);

export function getTheme(id) {
  return themes.find(theme => theme.id === id) || themes[0];
}

export function themeTemplate(design) {
  return getTheme(design?.theme_id).template;
}

export function validateThemeCatalog(catalog = themes) {
  if (!Array.isArray(catalog) || catalog.length === 0) throw new Error('Theme catalog must not be empty');
  const ids = new Set();
  for (const theme of catalog) {
    if (!theme || !/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(theme.id) || ids.has(theme.id)) throw new Error('Theme ids must be unique kebab-case strings');
    ids.add(theme.id);
    if (!Number.isInteger(theme.version) || theme.version < 1) throw new Error(`Invalid version for ${theme.id}`);
    if (!THEME_TEMPLATES.includes(theme.template)) throw new Error(`Unsupported template for ${theme.id}`);
    if (!theme.name || !theme.description || !theme.concept || !Array.isArray(theme.mood) || theme.mood.length < 3 || theme.mood.length > 5 || !theme.signature || !theme.quiet_area || !Array.isArray(theme.best_for) || !theme.defaults || !theme.customization || !theme.preview_image || !theme.visual_reference || !Array.isArray(theme.route_states) || !theme.structure) throw new Error(`Incomplete theme manifest for ${theme.id}`);
    if (JSON.stringify([...theme.route_states].sort()) !== JSON.stringify([...THEME_ROUTE_STATES].sort())) throw new Error(`Theme ${theme.id} must cover every required route state`);
    for (const key of THEME_STRUCTURE_KEYS) if (typeof theme.structure[key] !== 'string' || !theme.structure[key].trim()) throw new Error(`Theme ${theme.id} is missing structural acceptance: ${key}`);
    for (const key of ['palette', 'typography', 'layout']) {
      if (!Array.isArray(theme.customization[key]) || !theme.customization[key].includes(theme.defaults[key])) throw new Error(`Invalid ${key} options for ${theme.id}`);
    }
  }
  return catalog;
}

validateThemeCatalog();
export { themes };
