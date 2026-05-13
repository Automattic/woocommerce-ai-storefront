#!/usr/bin/env node
/**
 * Build the user-guide HTML from the canonical USER-GUIDE.md.
 *
 * Why this exists:
 *   The plugin's in-admin "Help → Documentation" menu item opens a
 *   standalone HTML page in a new tab. That page IS the user guide,
 *   served from the merchant's own server (the plugin's own files
 *   directory). The source-of-truth is USER-GUIDE.md; this script
 *   renders it to HTML at npm run build time so the production
 *   artifact ships with the plugin.
 *
 * Output location:
 *   docs/user-guide/USER-GUIDE.html — co-located with the markdown
 *   so the relative image paths inside the .md (e.g.,
 *   `screenshots/01-plugins-screen.png`) resolve correctly when the
 *   HTML is served from the same directory. Zero path rewriting
 *   needed at build time — the screenshots ship alongside.
 *
 * Why no admin sub-page:
 *   WordPress serves plain .html files from plugin directories by
 *   default (no PHP execution required, no admin chrome). Serving the
 *   guide as a static asset means no new REST endpoint, no new admin
 *   menu, no markdown-rendering-at-runtime dependency. Tradeoff: the
 *   page lacks WP admin chrome (sidebar, header). Acceptable here
 *   because the link opens in a new tab — the merchant's admin
 *   context isn't replaced.
 *
 * Why marked specifically:
 *   Small (~30KB), GitHub-flavored markdown out of the box, no plugin
 *   ecosystem dependencies. Matches the USER-GUIDE.md's GitHub-style
 *   formatting (tables, fenced code blocks, etc.) without extra
 *   config.
 *
 * Contributor note (mirrored in AGENTS.md):
 *   If you edit docs/user-guide/USER-GUIDE.md, run `npm run build`
 *   to regenerate USER-GUIDE.html. `npm start`'s watch mode does NOT
 *   rebuild this file — the watch is JS/CSS only.
 */

// ESM-only because the upstream `marked` package dropped CommonJS in
// v5+. Build-time-only script so the ESM choice is invisible to
// merchants — they ship the rendered HTML, not this file.

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

// Fail with a clear message if marked isn't installed yet (e.g., on a
// fresh clone before `npm install`).
let marked;
try {
	( { marked } = await import( 'marked' ) );
} catch ( err ) {
	console.error( '[build-user-guide] `marked` is not installed. Run `npm install` first.' );
	process.exit( 1 );
}

const __filename = fileURLToPath( import.meta.url );
const __dirname  = path.dirname( __filename );
const repoRoot   = path.join( __dirname, '..' );
const inputPath  = path.join( repoRoot, 'docs', 'user-guide', 'USER-GUIDE.md' );
const outputPath = path.join( repoRoot, 'docs', 'user-guide', 'USER-GUIDE.html' );

if ( ! fs.existsSync( inputPath ) ) {
	console.error( `[build-user-guide] Source not found: ${ inputPath }` );
	process.exit( 1 );
}

// Read package.json to source the canonical plugin version. The
// user guide's footer ("Covers AI Storefront X.Y.Z.") used to be
// a hand-edited literal that drifted multiple releases before
// anyone noticed. Reading from package.json — one of the three
// agreeing version sources per AGENTS.md — means the footer
// self-updates every time the version bumps for release.
//
// JSON.parse + fs is used here instead of `import ... with { type:
// "json" }` because the latter is still gated behind Node flags on
// some older Node versions and this script needs to "just work" on
// any CI image.
const pkgJsonPath = path.join( repoRoot, 'package.json' );
const pkg         = JSON.parse( fs.readFileSync( pkgJsonPath, 'utf8' ) );
const version     = pkg.version || 'unreleased';

// Read + substitute + render. `marked.parse()` is synchronous and
// produces GitHub-flavored markdown HTML. The {{VERSION}} token is
// replaced before parsing so the rendered output never carries
// the placeholder literally.
const markdown = fs
	.readFileSync( inputPath, 'utf8' )
	.replace( /\{\{VERSION\}\}/g, version );
const bodyHtml = marked.parse( markdown );

// Minimal inline stylesheet for typography + table/code legibility.
// Inline rather than a separate .css file so the HTML is a single
// self-contained file the merchant can save / share if they want.
//
// `max-width: 760px` matches typical long-form-prose width (e.g.,
// Medium, GitHub README rendering); centered for reading comfort on
// wide displays. Other values are conservative defaults — adjust if
// merchants give feedback that something reads poorly.
const html = `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WooCommerce AI Storefront User Guide</title>
<style>
  body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, sans-serif;
    line-height: 1.6;
    color: #1d2327;
    max-width: 760px;
    margin: 2rem auto;
    padding: 0 1.5rem;
  }
  h1, h2, h3, h4, h5, h6 { line-height: 1.25; margin-top: 1.5em; }
  h1 { font-size: 1.875rem; border-bottom: 1px solid #e5e7eb; padding-bottom: 0.3em; }
  h2 { font-size: 1.5rem; border-bottom: 1px solid #e5e7eb; padding-bottom: 0.2em; }
  h3 { font-size: 1.25rem; }
  h4 { font-size: 1.1rem; }
  a { color: #2271b1; }
  a:hover { color: #0a4b78; }
  code {
    background: #f6f7f7;
    padding: 0.1em 0.4em;
    border-radius: 3px;
    font-family: SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 0.9em;
  }
  pre {
    background: #f6f7f7;
    padding: 1em;
    border-radius: 4px;
    overflow-x: auto;
  }
  pre code { background: transparent; padding: 0; }
  table {
    border-collapse: collapse;
    width: 100%;
    margin: 1em 0;
  }
  th, td { border: 1px solid #ddd; padding: 0.5em 0.75em; text-align: left; }
  th { background: #f6f7f7; }
  blockquote {
    border-left: 4px solid #ddd;
    margin: 1em 0;
    padding: 0.1em 1em;
    color: #50575e;
  }
  img { max-width: 100%; height: auto; border: 1px solid #e5e7eb; border-radius: 4px; }
  hr { border: none; border-top: 1px solid #e5e7eb; margin: 2em 0; }
</style>
</head>
<body>
${ bodyHtml }
</body>
</html>
`;

fs.writeFileSync( outputPath, html, 'utf8' );
console.log( `[build-user-guide] Wrote ${ path.relative( repoRoot, outputPath ) } (${ html.length.toLocaleString() } bytes)` );
