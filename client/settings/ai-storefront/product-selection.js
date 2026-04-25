import {
	useState,
	useEffect,
	useMemo,
	createInterpolateElement,
} from '@wordpress/element';
import {
	Card,
	CardHeader,
	CardBody,
	CardFooter,
	Button,
	SearchControl,
	CheckboxControl,
	Spinner,
	Notice,
	// ToggleGroupControl and its Option are still exported under the
	// `__experimental` prefix as of @wordpress/components 28.x, but
	// they've been stable in practice for multiple years and are
	// used widely across Gutenberg + wc-admin. Keep the aliased
	// import so a future graduation to stable surface is a one-line
	// rename here. Suppressing `no-unsafe-wp-apis` at the specific
	// lines rather than file-wide so any OTHER experimental usage
	// added later still gets flagged.
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControl as ToggleGroupControl,
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalToggleGroupControlOption as ToggleGroupControlOption,
} from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { decodeEntities } from '@wordpress/html-entities';
import apiFetch from '@wordpress/api-fetch';
import { colors } from './tokens';

// Server-side enum for `product_selection_mode` (0.1.5+):
//
//   'all'          → every published product is syndicated
//   'by_taxonomy'  → UNION of selected_categories ∪ selected_tags ∪
//                    selected_brands
//   'selected'     → hand-picked individual products from
//                    selected_products
//
// Pre-0.1.5 used three distinct taxonomy modes (`categories`, `tags`,
// `brands`) that each enforced only one taxonomy at a time. Those
// values are still accepted via a defensive fallback in server-side
// code and migrated to `by_taxonomy` on first settings read. We do
// NOT expose them as active enum values in the client — anything
// the client commits is one of the three 0.1.5 values above.
const MODES = {
	ALL: 'all',
	BY_TAXONOMY: 'by_taxonomy',
	SELECTED: 'selected',
};

// Viewable taxonomy tabs inside the By-taxonomy row. These are local
// UI state (`activeTaxonomy`), NOT persisted modes. They drive which
// picker panel is visible and are used as seed values for the toggle-
// group segment. The strings happen to match the pre-0.1.5 server
// enum values (`'categories'`, `'tags'`, `'brands'`) so that a stale
// DB value read during the migration window can still map to a
// sensible default tab — but they're not written back to the server
// in 0.1.5+.
const TAXONOMY_TABS = {
	CATEGORIES: 'categories',
	TAGS: 'tags',
	BRANDS: 'brands',
};

const TAXONOMY_TAB_VALUES = [
	TAXONOMY_TABS.CATEGORIES,
	TAXONOMY_TABS.TAGS,
	TAXONOMY_TABS.BRANDS,
];

// UI-side grouping: three radio rows map 1:1 to the three server modes.
const UI_ROWS = {
	ALL: 'all',
	BY_TAXONOMY: 'by_taxonomy',
	SELECTED: 'selected',
};

/**
 * Map the server-persisted `product_selection_mode` to the UI row it
 * belongs to.
 *
 * Under 0.1.5 this is a direct 1:1 mapping (modes and rows share
 * values). Legacy pre-0.1.5 values (`categories` / `tags` / `brands`)
 * are still recognized defensively — they'd normally be migrated by
 * the server's silent migration on first settings read, but a
 * settings payload in flight during the migration window might still
 * carry one of them; map all three to BY_TAXONOMY.
 *
 * @param {string} mode Server mode value.
 * @return {string}     Corresponding UI row key.
 */
const modeToUiRow = ( mode ) => {
	if ( mode === MODES.BY_TAXONOMY || TAXONOMY_TAB_VALUES.includes( mode ) ) {
		return UI_ROWS.BY_TAXONOMY;
	}
	if ( mode === MODES.SELECTED ) {
		return UI_ROWS.SELECTED;
	}
	return UI_ROWS.ALL;
};

// Short, action-oriented descriptions surfaced under each radio row's
// label. The longer "what this means" narrative lives in the disclosure
// line at the bottom of each detail panel.
const MODE_DESCRIPTIONS = {
	[ UI_ROWS.ALL ]: __(
		'Every published product in your store is discoverable by AI crawlers.',
		'woocommerce-ai-storefront'
	),
	[ UI_ROWS.BY_TAXONOMY ]: __(
		'Share only products that match selected categories, tags, or brands.',
		'woocommerce-ai-storefront'
	),
	[ UI_ROWS.SELECTED ]: __(
		'Hand-pick individual products. New products are not auto-included.',
		'woocommerce-ai-storefront'
	),
};

// Spec-aligned list of fields our endpoints serialize per product.
// Surfaced in the shared footer as "Included fields" chips so merchants
// see at a glance what each AI agent receives. Keep this list aligned
// with the UCP core product/variant payload definition and the PHP
// product + variant translators that emit those on-the-wire fields
// (class-wc-ai-storefront-ucp-product-translator.php and
// class-wc-ai-storefront-ucp-variant-translator.php).
// `handle_extension_schema()` documents the merchant extension contract
// (config + accepted_request_inputs) — NOT the core serialized product
// fields, so it's not the source of truth for this list.
// `key` is a stable React-key identifier; `label` is the translated
// user-visible string. Built inside the component rather than at
// module scope so `__()` picks up runtime locale changes.
const getIncludedFields = () => [
	{ key: 'name', label: __( 'name', 'woocommerce-ai-storefront' ) },
	{
		key: 'description',
		label: __( 'description', 'woocommerce-ai-storefront' ),
	},
	{ key: 'price', label: __( 'price', 'woocommerce-ai-storefront' ) },
	{ key: 'stock', label: __( 'stock', 'woocommerce-ai-storefront' ) },
	{ key: 'images', label: __( 'images', 'woocommerce-ai-storefront' ) },
	{
		key: 'categories',
		label: __( 'categories', 'woocommerce-ai-storefront' ),
	},
	{ key: 'sku', label: __( 'SKU', 'woocommerce-ai-storefront' ) },
];

/**
 * Count pill used next to radio-row labels.
 *
 * We built our own rather than using `@wordpress/components` `Badge`
 * because Badge emits an "outlined" style by default that clashes with
 * the filled selected-state treatment (blue pill on selected, gray on
 * unselected). Kept tiny and semantic (bg + fg swap) so the selected
 * row self-announces in the three-row card.
 *
 * Returns `null` when `label` is empty/falsy so call sites can
 * suppress the badge by passing `''` without rendering a blank pill.
 * The By-taxonomy row relies on this: on mode=`all` / mode=`selected`
 * with no configured taxonomy terms, the taxonomyBadge is `''` and
 * the row renders badge-less rather than as a blank pill.
 *
 * @param {Object}  root0          Component props.
 * @param {string}  root0.label    Text content (pre-formatted).
 * @param {boolean} root0.selected Whether the parent row is selected.
 */
const ModeBadge = ( { label, selected } ) => {
	if ( ! label ) {
		return null;
	}
	return (
		<span
			style={ {
				background: selected ? colors.link : colors.surfaceMuted,
				color: selected ? '#fff' : colors.textSecondary,
				padding: '2px 10px',
				borderRadius: '10px',
				fontSize: '12px',
				fontWeight: '600',
				flexShrink: 0,
				whiteSpace: 'nowrap',
			} }
		>
			{ label }
		</span>
	);
};

/**
 * Token list showing currently-selected items with remove buttons.
 * `variant="tag"` renders pill-shaped tokens with a leading `#` to
 * visually distinguish tags from categories and brands (both of which
 * render as rectangular tokens) at a glance — important when a
 * merchant switches between the three taxonomy sub-modes and wants to
 * confirm "am I looking at the tag list or the category list" without
 * re-reading the heading.
 *
 * @param {Object}   root0           Component props.
 * @param {Array}    root0.items     Selected items with { id, name }.
 * @param {Function} root0.onRemove  Callback when an item is removed.
 * @param {string}   [root0.variant] 'tag' renders pill + `#` prefix.
 */
const SelectedTokens = ( { items, onRemove, variant } ) => {
	if ( items.length === 0 ) {
		return null;
	}

	const isTag = variant === 'tag';

	return (
		<div
			style={ {
				display: 'flex',
				flexWrap: 'wrap',
				gap: '6px',
				marginBottom: '12px',
				padding: '10px 12px',
				background: colors.surface,
				border: `1px solid ${ colors.borderSubtle }`,
				borderRadius: '3px',
			} }
		>
			{ items.map( ( item ) => (
				<span
					key={ item.id }
					style={ {
						display: 'inline-flex',
						alignItems: 'center',
						gap: '4px',
						background: colors.surfaceMuted,
						// Tags get a fully-rounded pill; categories +
						// brands keep the existing 3px rectangular
						// radius so the three taxonomies are visually
						// distinct in the UI.
						borderRadius: isTag ? '12px' : '3px',
						padding: '3px 6px 3px 10px',
						fontSize: '12px',
						color: colors.textPrimary,
						lineHeight: '1.4',
					} }
				>
					{ isTag && (
						<span
							aria-hidden="true"
							style={ {
								color: colors.textMuted,
								fontWeight: '500',
							} }
						>
							#
						</span>
					) }
					{ decodeEntities( item.name ) }
					<button
						type="button"
						onClick={ () => onRemove( item.id ) }
						style={ {
							background: 'none',
							border: 'none',
							padding: '0 2px',
							cursor: 'pointer',
							fontSize: '14px',
							lineHeight: '1',
							color: colors.textMuted,
						} }
						aria-label={ sprintf(
							/* translators: %s: item name */
							__( 'Remove %s', 'woocommerce-ai-storefront' ),
							decodeEntities( item.name )
						) }
					>
						{ '\u00D7' }
					</button>
				</span>
			) ) }
		</div>
	);
};

/**
 * Radio-card row. Wraps a native `<input type="radio">` inside a
 * `<label>` so keyboard nav (Tab between the rows, Arrow keys within
 * the radio group) and screen-reader semantics work without any JS
 * — the @wordpress/components `RadioControl` component can't host
 * rich per-option children, so we use the primitive directly and
 * style the wrapping label as a clickable card. The implicit label
 * association (the native behavior when an input is nested in a
 * label) gives us click-target expansion + screen-reader labeling
 * without needing an explicit `htmlFor`/`id` pair.
 *
 * The radio input itself renders the browser-default focus
 * indicator; we don't paint a card-level focus ring because there's
 * no external stylesheet in this plugin (all styling is inline
 * style props, which can't hold `:focus-within` pseudo-class rules).
 *
 * Selected state: a 3px solid WP-blue left border runs down the row
 * AND its detail panel, matching the WP-native Notice focus-ring
 * pattern. Tested against a background-tint-only treatment at real
 * admin dimensions; the left border is substantially more scannable
 * for a merchant sweeping three rows to find the selected one.
 *
 * Selected state renders the children (detail panel) below the label
 * row; unselected state hides them entirely (not just visually) so
 * the DOM stays small and assistive tech doesn't read hidden content.
 *
 * @param {Object}                                             root0             Component props.
 * @param {string}                                             root0.value       This option's value.
 * @param {string}                                             root0.selected    Currently-selected option value.
 * @param {string}                                             root0.name        Radio group name.
 * @param {string}                                             root0.label       Option label (bold).
 * @param {string}                                             root0.description Option description (muted).
 * @param {string}                                             root0.badgeLabel  Text for the right-aligned badge.
 * @param {Function}                                           root0.onSelect
 *                                                                               Called with this option's value.
 * @param {JSX.Element|JSX.Element[]|string|number|null|false} root0.children
 *                                                                               Detail panel content (rendered when selected).
 * @param {boolean}                                            root0.isLast
 *                                                                               Suppresses the label's bottom border regardless of selection state.
 */
const ModeRow = ( {
	value,
	selected,
	name,
	label,
	description,
	badgeLabel,
	onSelect,
	children,
	isLast,
} ) => {
	const isSelected = selected === value;
	// Selected-state accent: 3px WP-blue left border on both the
	// label row and the detail panel, stitched together by a shared
	// background tint so they read as one element. We paint the
	// border by overriding `paddingLeft` to keep total horizontal
	// dimensions identical to the unselected state (no layout shift
	// on selection change).
	const selectedAccentWidth = 3;
	const labelPaddingLeft = isSelected
		? `${ 20 - selectedAccentWidth }px`
		: '20px';

	return (
		<>
			{ /*
			   `jsx-a11y/label-has-associated-control` rule is stricter
			   than the HTML spec — it requires either an explicit
			   `htmlFor`/`id` pair or declares the association
			   heuristically. Here the label implicitly associates via
			   the nested <input type="radio"> child (valid HTML since
			   HTML4), which AT announces correctly. Explicit
			   `htmlFor` + nested input is an HTML conformance error
			   per Copilot's review, so we keep the nesting-only form
			   and disable the linter for this specific case.
			*/ }
			{ /* eslint-disable-next-line jsx-a11y/label-has-associated-control */ }
			<label
				style={ {
					display: 'flex',
					alignItems: 'center',
					gap: '12px',
					padding: `14px 20px 14px ${ labelPaddingLeft }`,
					// The label's bottom border separates collapsed rows from
					// each other. When this row is `isLast` we always drop
					// the border — whether selected or not — because the
					// enclosing Card already draws the outer border, and an
					// extra line between the last row and the card edge
					// produces a visible double-border on collapsed last,
					// or a floating divider above the Save button on
					// selected last.
					borderBottom: isLast
						? 'none'
						: `1px solid ${ colors.borderSubtle }`,
					borderLeft: isSelected
						? `${ selectedAccentWidth }px solid ${ colors.link }`
						: 'none',
					background: isSelected ? '#f6fbfd' : 'transparent',
					cursor: 'pointer',
				} }
			>
				<input
					type="radio"
					name={ name }
					value={ value }
					checked={ isSelected }
					onChange={ () => onSelect( value ) }
					style={ { margin: 0, accentColor: colors.link } }
				/>
				<span style={ { flex: 1, minWidth: 0 } }>
					<span
						style={ {
							display: 'block',
							fontSize: '14px',
							fontWeight: '600',
							color: colors.textPrimary,
						} }
					>
						{ label }
					</span>
					<span
						style={ {
							display: 'block',
							fontSize: '13px',
							color: colors.textSecondary,
							marginTop: '2px',
						} }
					>
						{ description }
					</span>
				</span>
				<ModeBadge label={ badgeLabel } selected={ isSelected } />
			</label>
			{ /*
			   Detail panel only renders when the row is selected AND
			   has content to show. A selected row with no children
			   (e.g. the "All published products" row — nothing to
			   configure) collapses cleanly rather than opening an
			   empty blue-tinted strip with a floating horizontal rule.
			   The count pill + header description already convey the
			   full state for zero-config modes.
			*/ }
			{ isSelected && children && (
				<div
					style={ {
						padding: `0 20px 18px ${ 50 - selectedAccentWidth }px`,
						background: '#f6fbfd',
						borderLeft: `${ selectedAccentWidth }px solid ${ colors.link }`,
						borderBottom: isLast
							? 'none'
							: `1px solid ${ colors.borderSubtle }`,
					} }
				>
					{ children }
				</div>
			) }
		</>
	);
};

const ProductSelection = ( { settings, onChange, onSave, isSaving } ) => {
	// `supportsBrands` comes from `wp_localize_script` and reflects
	// `taxonomy_exists( 'product_brand' )` at page load. When false,
	// the Brands toggle segment is hidden and the /search/brands
	// fetch is skipped — nothing on the client fails if an older WC
	// version is in use.
	/* global wcAiSyndicationParams */
	const supportsBrands =
		typeof wcAiSyndicationParams !== 'undefined'
			? Boolean( wcAiSyndicationParams.supportsBrands )
			: false;

	const [ categories, setCategories ] = useState( [] );
	const [ tags, setTags ] = useState( [] );
	const [ brands, setBrands ] = useState( [] );
	const [ products, setProducts ] = useState( [] );
	const [ productSearch, setProductSearch ] = useState( '' );
	const [ categorySearch, setCategorySearch ] = useState( '' );
	const [ tagSearch, setTagSearch ] = useState( '' );
	const [ brandSearch, setBrandSearch ] = useState( '' );
	const [ isLoadingCategories, setIsLoadingCategories ] = useState( false );
	const [ isLoadingTags, setIsLoadingTags ] = useState( false );
	const [ isLoadingBrands, setIsLoadingBrands ] = useState( false );
	const [ isLoadingProducts, setIsLoadingProducts ] = useState( false );
	// Per-taxonomy fetch-error flags. Without these, a failed
	// fetch (network drop, auth, server error) is indistinguishable
	// from a "merchant has never created any terms" state because
	// the catch blocks below leave `categories`/`tags`/`brands` at
	// the initial `[]`. Downstream code in TaxonomyPicker branches
	// on these flags to render a distinct warning Notice instead
	// of the "You haven't created any categories yet" empty-label
	// copy, so merchants aren't told they're missing data they
	// actually have.
	const [ hasCategoriesError, setHasCategoriesError ] = useState( false );
	const [ hasTagsError, setHasTagsError ] = useState( false );
	const [ hasBrandsError, setHasBrandsError ] = useState( false );

	const serverMode = settings.product_selection_mode || MODES.ALL;

	const effectiveMode = serverMode;
	const uiRow = modeToUiRow( effectiveMode );

	// Seed the active taxonomy tab from whichever `selected_*` array
	// has content. Under 0.1.5's UNION enforcement the server mode is
	// just `by_taxonomy` — there's no per-taxonomy signal to carry a
	// "last-viewed tab" through a save. Deriving a default from
	// populated selections preserves the pre-0.1.5 behavior
	// meaningfully: a merchant with only brands selected lands on
	// the Brands tab; one with only tags lands on Tags; ties fall
	// back to Categories (the leftmost, most-common choice). Brands
	// is only an option when `supportsBrands` is true — a downgrade
	// scenario with stale `selected_brands` data doesn't accidentally
	// seed a tab the ToggleGroup can't render.
	const [ activeTaxonomy, setActiveTaxonomy ] = useState( () => {
		const hasCats = ( settings.selected_categories || [] ).length > 0;
		const hasTags = ( settings.selected_tags || [] ).length > 0;
		const hasBrands =
			( settings.selected_brands || [] ).length > 0 && supportsBrands;
		if ( hasCats ) {
			return TAXONOMY_TABS.CATEGORIES;
		}
		if ( hasTags ) {
			return TAXONOMY_TABS.TAGS;
		}
		if ( hasBrands ) {
			return TAXONOMY_TABS.BRANDS;
		}
		return TAXONOMY_TABS.CATEGORIES;
	} );

	// Defensive sync: if the merchant is currently viewing the Brands
	// tab and `supportsBrands` flips to false mid-session (plugin
	// deactivation, custom taxonomy unregister), fall back to the
	// Categories tab so the UI doesn't render a picker for a taxonomy
	// that no longer has a toggle segment.
	useEffect( () => {
		if ( ! supportsBrands && activeTaxonomy === TAXONOMY_TABS.BRANDS ) {
			setActiveTaxonomy( TAXONOMY_TABS.CATEGORIES );
		}
	}, [ supportsBrands, activeTaxonomy ] );

	// Load categories eagerly on mount (cheap, small list) so the
	// By-Taxonomy radio row can show an accurate count even when
	// unselected, and the merchant sees the list immediately on row
	// expansion.
	//
	// On fetch failure, set `hasCategoriesError` so TaxonomyPicker
	// surfaces a distinct warning Notice instead of the
	// "You haven't created any categories yet" empty-label copy.
	// Array-shape guard also flips the error flag: an unexpected
	// non-array response (HTML error page, WP_Error envelope,
	// cached garbage) is functionally a fetch failure — we can't
	// display terms we can't read, and the merchant deserves the
	// same "couldn't load" affordance we give to network errors.
	useEffect( () => {
		setIsLoadingCategories( true );
		setHasCategoriesError( false );
		apiFetch( {
			path: '/wc/v3/ai-storefront/admin/search/categories',
		} )
			.then( ( result ) => {
				if ( Array.isArray( result ) ) {
					setCategories( result );
				} else {
					setHasCategoriesError( true );
				}
			} )
			.catch( () => setHasCategoriesError( true ) )
			.finally( () => setIsLoadingCategories( false ) );
	}, [] );

	// Tags: same pattern as categories — eager mount fetch so the
	// toggle segment is instantly populated when the merchant clicks
	// into the Tags sub-mode. Same error-flag handling as above.
	useEffect( () => {
		setIsLoadingTags( true );
		setHasTagsError( false );
		apiFetch( {
			path: '/wc/v3/ai-storefront/admin/search/tags',
		} )
			.then( ( result ) => {
				if ( Array.isArray( result ) ) {
					setTags( result );
				} else {
					setHasTagsError( true );
				}
			} )
			.catch( () => setHasTagsError( true ) )
			.finally( () => setIsLoadingTags( false ) );
	}, [] );

	// Brands: only fetch when the server has `product_brand` registered
	// (WC 9.5+). Skipping the fetch on older stores avoids a pointless
	// round-trip to an endpoint that will return [] anyway and keeps
	// dev-tools network panels quiet.
	useEffect( () => {
		if ( ! supportsBrands ) {
			return;
		}
		setIsLoadingBrands( true );
		setHasBrandsError( false );
		apiFetch( {
			path: '/wc/v3/ai-storefront/admin/search/brands',
		} )
			.then( ( result ) => {
				if ( Array.isArray( result ) ) {
					setBrands( result );
				} else {
					setHasBrandsError( true );
				}
			} )
			.catch( () => setHasBrandsError( true ) )
			.finally( () => setIsLoadingBrands( false ) );
	}, [ supportsBrands ] );

	// Product search only runs when the merchant is actively in the
	// 'selected' mode — no point loading a product list they won't see.
	useEffect( () => {
		if ( serverMode !== MODES.SELECTED ) {
			return;
		}
		setIsLoadingProducts( true );
		apiFetch( {
			path: `/wc/v3/ai-storefront/admin/search/products?search=${ encodeURIComponent(
				productSearch
			) }`,
		} )
			// Same Array.isArray guard as categories — downstream
			// `products.map` + `products.forEach` would crash on a
			// non-array response.
			.then( ( result ) =>
				setProducts( Array.isArray( result ) ? result : [] )
			)
			.catch( () => {} )
			.finally( () => setIsLoadingProducts( false ) );
	}, [ productSearch, serverMode ] );

	const selectedCategories = useMemo(
		() => settings.selected_categories || [],
		[ settings.selected_categories ]
	);
	const selectedTags = useMemo(
		() => settings.selected_tags || [],
		[ settings.selected_tags ]
	);
	const selectedBrands = useMemo(
		() => settings.selected_brands || [],
		[ settings.selected_brands ]
	);
	const selectedProducts = useMemo(
		() => settings.selected_products || [],
		[ settings.selected_products ]
	);

	// Toggle handlers are the commit point for By-taxonomy scoping:
	// clicking a term is the moment the merchant commits to scoping
	// by that taxonomy, so both the selection array AND the
	// `product_selection_mode` are written in a single atomic
	// `onChange` call (splitting into two would create a half-
	// committed intermediate state where mode is `categories` but
	// `selected_categories` hasn't been updated yet, briefly tripping
	// the empty-selection warning for one render).
	//
	// Tab clicks (see `setTaxonomy` below) are pure browse and do
	// NOT write the mode — the commit is reserved for deliberate
	// term-selection actions.
	//
	// Mode-commit guard: only flip `product_selection_mode` when the
	// post-update array is non-empty. An UNCHECK that empties the
	// selection would otherwise silently commit `mode=categories +
	// selected=[]` — the empty-selection policy hides the entire
	// catalog from agents, and the merchant thought they were just
	// deselecting one item. Leaving the mode alone on the empty-case
	// keeps the merchant's previous mode (e.g. `brands` with a
	// non-empty selection on disk) in effect; entering the "hide
	// everything" posture requires an explicit By-taxonomy +
	// Categories confirmation via the ModeRow + ToggleGroup.
	const toggleCategory = ( catId ) => {
		const updated = selectedCategories.includes( catId )
			? selectedCategories.filter( ( id ) => id !== catId )
			: [ ...selectedCategories, catId ];
		const changes = { selected_categories: updated };
		if ( updated.length > 0 ) {
			changes.product_selection_mode = MODES.BY_TAXONOMY;
		}
		onChange( changes );
	};

	const toggleTag = ( tagId ) => {
		const updated = selectedTags.includes( tagId )
			? selectedTags.filter( ( id ) => id !== tagId )
			: [ ...selectedTags, tagId ];
		const changes = { selected_tags: updated };
		if ( updated.length > 0 ) {
			changes.product_selection_mode = MODES.BY_TAXONOMY;
		}
		onChange( changes );
	};

	const toggleBrand = ( brandId ) => {
		const updated = selectedBrands.includes( brandId )
			? selectedBrands.filter( ( id ) => id !== brandId )
			: [ ...selectedBrands, brandId ];
		const changes = { selected_brands: updated };
		if ( updated.length > 0 ) {
			changes.product_selection_mode = MODES.BY_TAXONOMY;
		}
		onChange( changes );
	};

	const toggleProduct = ( productId ) => {
		const updated = selectedProducts.includes( productId )
			? selectedProducts.filter( ( id ) => id !== productId )
			: [ ...selectedProducts, productId ];
		onChange( { selected_products: updated } );
	};

	const filteredCategories = useMemo( () => {
		if ( ! categorySearch.trim() ) {
			return categories;
		}
		const term = categorySearch.toLowerCase();
		return categories.filter( ( cat ) =>
			decodeEntities( cat.name ).toLowerCase().includes( term )
		);
	}, [ categories, categorySearch ] );

	const filteredTags = useMemo( () => {
		if ( ! tagSearch.trim() ) {
			return tags;
		}
		const term = tagSearch.toLowerCase();
		return tags.filter( ( tag ) =>
			decodeEntities( tag.name ).toLowerCase().includes( term )
		);
	}, [ tags, tagSearch ] );

	const filteredBrands = useMemo( () => {
		if ( ! brandSearch.trim() ) {
			return brands;
		}
		const term = brandSearch.toLowerCase();
		return brands.filter( ( brand ) =>
			decodeEntities( brand.name ).toLowerCase().includes( term )
		);
	}, [ brands, brandSearch ] );

	const selectedCategoryTokens = useMemo( () => {
		return categories.filter( ( cat ) =>
			selectedCategories.includes( cat.id )
		);
	}, [ categories, selectedCategories ] );

	const selectedTagTokens = useMemo( () => {
		return tags.filter( ( tag ) => selectedTags.includes( tag.id ) );
	}, [ tags, selectedTags ] );

	const selectedBrandTokens = useMemo( () => {
		return brands.filter( ( brand ) =>
			selectedBrands.includes( brand.id )
		);
	}, [ brands, selectedBrands ] );

	// Products carry a visibility problem: the merchant's selection
	// may reference products that aren't in the current (search-
	// filtered) result set, so the token list would go empty on every
	// search that doesn't match the selection. Cache product objects
	// as we see them so tokens survive searches.
	const [ selectedProductCache, setSelectedProductCache ] = useState( {} );
	useEffect( () => {
		if ( products.length === 0 ) {
			return;
		}
		setSelectedProductCache( ( prev ) => {
			const next = { ...prev };
			products.forEach( ( p ) => {
				next[ p.id ] = p;
			} );
			return next;
		} );
	}, [ products ] );

	const selectedProductTokens = useMemo( () => {
		return selectedProducts
			.map( ( id ) => selectedProductCache[ id ] )
			.filter( Boolean );
	}, [ selectedProducts, selectedProductCache ] );

	// Badge labels — per-row, reflect the current configuration.
	// The "all" badge needs the total published-product count even
	// when the merchant is currently in `categories`/`tags`/`brands`/
	// `selected` mode, so it answers "how many products would be
	// shared if I switched to All?" — i.e. the unfiltered total.
	//
	// We hit the WC admin REST endpoint (`/wc/v3/products`) rather
	// than the Store API (`/wc/store/v1/products`) because this
	// plugin globally restricts Store API product collections via
	// the `woocommerce_store_api_product_collection_query_args`
	// filter when mode is not 'all' — that filter would make the
	// Store API count reflect the merchant's current selection, not
	// the true unfiltered total.
	//
	// We also don't use the WP core endpoint (`/wp/v2/product`)
	// because it gates on `edit_posts`, which plugin admins with
	// only `manage_woocommerce` don't always have — such a merchant
	// would hit a 401 here and see a bare "Products" badge label.
	// `/wc/v3/products` is gated on `manage_woocommerce` to match
	// this plugin's own admin permission checks.
	//
	// Three states:
	//   - null     → still loading → show "Loading…"
	//   - 'error'  → fetch failed or response was unusable → show
	//                the generic label without a count ("Products")
	//   - number   → finite integer → format + render
	const [ totalPublished, setTotalPublished ] = useState( null );
	useEffect( () => {
		let cancelled = false;
		apiFetch( {
			path: '/wc/v3/products?status=publish&per_page=1&_fields=id',
			parse: false,
		} )
			.then( ( response ) => {
				if ( cancelled ) {
					return;
				}
				// Non-2xx (401/403/5xx) resolves .then() when
				// `parse: false` — check `response.ok` explicitly so
				// an auth failure or server error maps to our 'error'
				// sentinel instead of silently parsing an error body.
				if ( ! response.ok ) {
					setTotalPublished( 'error' );
					return;
				}
				const totalHeader = response.headers.get( 'X-WP-Total' );
				const parsed =
					totalHeader !== null ? parseInt( totalHeader, 10 ) : NaN;
				setTotalPublished(
					Number.isFinite( parsed ) ? parsed : 'error'
				);
			} )
			.catch( () => {
				if ( ! cancelled ) {
					setTotalPublished( 'error' );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [] );

	let allBadge;
	if ( totalPublished === null ) {
		allBadge = __( 'Loading\u2026', 'woocommerce-ai-storefront' );
	} else if ( totalPublished === 'error' ) {
		// Don't show a count we can't trust; a bare "Products"
		// label is still accurate and won't mislead merchants
		// about catalog size.
		allBadge = __( 'Products', 'woocommerce-ai-storefront' );
	} else {
		allBadge = sprintf(
			/* translators: %s: total published product count, formatted via toLocaleString using the browser locale. */
			_n(
				'%s product',
				'%s products',
				totalPublished,
				'woocommerce-ai-storefront'
			),
			totalPublished.toLocaleString()
		);
	}

	// By-taxonomy row badge: surfaces every non-zero taxonomy count
	// so merchants see what's actually being shared regardless of
	// which tab is open. Reads "1 brand", "3 categories · 1 brand",
	// or "Nothing selected" as the count picture changes.
	//
	// Only ONE of the three counts is actively filtering products
	// (whichever matches `effectiveMode`). The others are stored-
	// but-inert data, preserved across taxonomy switches so a
	// merchant flipping between scoping modes doesn't lose their
	// prior selections. The split between "viewed" and "enforcing"
	// is visible through a combination of badge + tab-highlight
	// (ToggleGroupControl below) + inline warning (fires only when
	// the enforcing taxonomy has zero selections) — no single
	// element has to convey all three axes.
	const taxonomyBadgeParts = [];
	if ( selectedCategories.length > 0 ) {
		taxonomyBadgeParts.push(
			sprintf(
				/* translators: %d: number of selected categories. */
				_n(
					'%d category',
					'%d categories',
					selectedCategories.length,
					'woocommerce-ai-storefront'
				),
				selectedCategories.length
			)
		);
	}
	if ( selectedTags.length > 0 ) {
		taxonomyBadgeParts.push(
			sprintf(
				/* translators: %d: number of selected tags. */
				_n(
					'%d tag',
					'%d tags',
					selectedTags.length,
					'woocommerce-ai-storefront'
				),
				selectedTags.length
			)
		);
	}
	// Brands segment is gated on `supportsBrands`. On a store where
	// the `product_brand` taxonomy isn't registered (pre-WC-9.5, or a
	// custom env that unregistered it), the Brands tab is hidden and
	// the merchant has no way to view or edit a stored `selected_brands`
	// selection. Showing "N brands" in the badge there would advertise
	// a count the merchant can't act on — possibly from a prior
	// configuration when the taxonomy was still available (stale data
	// survives the downgrade). Hide the segment so the badge matches
	// what's actually editable in the UI.
	if ( supportsBrands && selectedBrands.length > 0 ) {
		taxonomyBadgeParts.push(
			sprintf(
				/* translators: %d: number of selected brands. */
				_n(
					'%d brand',
					'%d brands',
					selectedBrands.length,
					'woocommerce-ai-storefront'
				),
				selectedBrands.length
			)
		);
	}
	// Separator exposed as a translatable string rather than a
	// hard-coded ' · ' so RTL and non-Latin locales can supply a
	// glyph that wraps correctly with surrounding text direction
	// (e.g. Arabic/Hebrew might prefer ' ، ' or omit the spaces).
	// Middle-dot + hair spaces happen to work in most LTR languages
	// but aren't universally appropriate.
	const badgeSeparator = __(
		/* translators: separator between taxonomy count segments in the By-taxonomy row badge, e.g. "3 categories · 1 brand" */
		' \u00B7 ',
		'woocommerce-ai-storefront'
	);
	// "Nothing selected" only appears when the merchant has actually
	// committed to By-taxonomy scoping — on `all` / `selected` modes
	// the By-taxonomy row's count is purely advisory (zero counts
	// mean "you haven't configured this mode"), and showing
	// "Nothing selected" on an unselected row reads as an error
	// state the merchant hasn't opted into. When BY_TAXONOMY is the
	// active row, the same copy IS informative: it signals the
	// empty-selection policy that hides everything.
	let taxonomyBadge = '';
	if ( taxonomyBadgeParts.length > 0 ) {
		taxonomyBadge = taxonomyBadgeParts.join( badgeSeparator );
	} else if ( uiRow === UI_ROWS.BY_TAXONOMY ) {
		taxonomyBadge = __( 'Nothing selected', 'woocommerce-ai-storefront' );
	}

	// No plural-form distinction for this copy ('%d selected' reads
	// the same for singular + plural in English), so use a single
	// translatable string via __() rather than _n(). Locales with
	// distinct plural forms can override as needed.
	const selectedBadge = sprintf(
		/* translators: %d: number of selected products. */
		__( '%d selected', 'woocommerce-ai-storefront' ),
		selectedProducts.length
	);

	// Empty-scoping warning — UNION semantics.
	//
	// Under 0.1.5 `by_taxonomy` mode enforces the UNION of
	// `selected_categories ∪ selected_tags ∪ selected_brands`.
	// The warning fires only when NONE of the three arrays has an
	// enforceable selection — i.e. saving would hide the entire
	// catalog from AI agents.
	//
	// "Enforceable" for brands means "selected AND the taxonomy is
	// registered." If a merchant has only `selected_brands` set on
	// a store where `product_brand` is unregistered (pre-WC 9.5 /
	// custom unregistration), the server-side downgrade policy
	// returns "show all" rather than hiding — so the UI suppresses
	// the warning too. This keeps the visible state consistent with
	// the merchant's actual post-save experience.
	//
	// Copy enumerates all three dimensions so a merchant on any tab
	// understands the resolution path ("pick a category, tag, or
	// brand"). Before 0.1.5 the copy named the specific taxonomy
	// of the enforcing mode, but UNION means all three are equally
	// enforcing — single-taxonomy copy would mislead.
	const hasCategoriesSelected = selectedCategories.length > 0;
	const hasTagsSelected = selectedTags.length > 0;
	const hasEnforceableBrandsSelected =
		selectedBrands.length > 0 && supportsBrands;
	const onlyBrandsSetButUnsupported =
		selectedBrands.length > 0 &&
		! supportsBrands &&
		! hasCategoriesSelected &&
		! hasTagsSelected;
	const emptyEnforcingSelection =
		effectiveMode === MODES.BY_TAXONOMY &&
		! hasCategoriesSelected &&
		! hasTagsSelected &&
		! hasEnforceableBrandsSelected &&
		! onlyBrandsSetButUnsupported;
	const emptyTaxonomyWarning = supportsBrands
		? __(
				'No categories, tags, or brands selected. Your products are currently hidden from AI agents — pick at least one to resume sharing.',
				'woocommerce-ai-storefront'
		  )
		: __(
				'No categories or tags selected. Your products are currently hidden from AI agents — pick at least one to resume sharing.',
				'woocommerce-ai-storefront'
		  );

	// Switch UI rows → write the corresponding server mode. Under
	// 0.1.5 there are three enum values (`all` / `by_taxonomy` /
	// `selected`) and each radio row maps 1:1. Pre-0.1.5 code had
	// to pick between multiple taxonomy-specific mode values on
	// By-taxonomy row selection; UNION enforcement eliminated that
	// branch.
	const setRow = ( row ) => {
		if ( row === UI_ROWS.ALL ) {
			onChange( { product_selection_mode: MODES.ALL } );
			return;
		}
		if ( row === UI_ROWS.SELECTED ) {
			onChange( { product_selection_mode: MODES.SELECTED } );
			return;
		}
		// Under 0.1.5 `by_taxonomy` is the only server-side value
		// for "the merchant is scoping by any combination of
		// categories, tags, and brands." Single write, no per-tab
		// variants to choose between.
		onChange( { product_selection_mode: MODES.BY_TAXONOMY } );
	};

	// Switch between Categories / Tags / Brands inside the toggle.
	//
	// IMPORTANT: tab click is BROWSE-ONLY. The persisted mode
	// (`product_selection_mode`) is NOT flipped here — that write
	// happens when the merchant picks a term in the tab (see
	// `toggleCategory` / `toggleTag` / `toggleBrand`), or explicitly
	// selects the By-taxonomy row (`setRow`). This split between
	// view state (local `activeTaxonomy`) and commit state
	// (persisted `product_selection_mode`) lets merchants browse
	// taxonomy tabs without accidentally flipping their saved
	// scope.
	//
	// Stale `selected_*` arrays: switching the active taxonomy
	// doesn't clear `selected_tags` / `selected_brands` /
	// `selected_categories`. Server enforcement only reads the
	// array matching the persisted `product_selection_mode`, so
	// the inactive arrays are inert data — preserving them lets a
	// merchant flip back and forth between taxonomies without
	// losing their work. If a future server-side migration reads
	// multiple arrays at once, this comment is the reminder to
	// clear the inactive ones on a taxonomy switch.
	const setTaxonomy = ( taxonomy ) => {
		// Defensive membership check rather than a plain falsy
		// guard: `! taxonomy` would correctly swallow the
		// `undefined` that ToggleGroupControl emits on re-selection
		// of the current option, but it would also swallow a
		// numeric `0` from a future consumer. Checking the enum
		// directly matches the three values this handler is
		// actually designed to accept.
		if ( ! TAXONOMY_TAB_VALUES.includes( taxonomy ) ) {
			// Silent in production (re-selection of the current
			// ToggleGroupControl option is the expected non-enum
			// path — ignoring it is correct), but surface a warn in
			// dev so a genuine out-of-enum value from a future
			// consumer doesn't get silently dropped. Guard with
			// NODE_ENV so prod bundles strip the console call via
			// dead-code elimination.
			if (
				process.env.NODE_ENV !== 'production' &&
				taxonomy !== undefined
			) {
				// eslint-disable-next-line no-console
				console.warn(
					'setTaxonomy: ignored out-of-enum value',
					taxonomy
				);
			}
			return;
		}
		setActiveTaxonomy( taxonomy );
	};

	return (
		<div>
			<Card>
				<CardHeader>
					<div>
						<h3
							style={ {
								margin: 0,
								fontSize: '16px',
								fontWeight: '600',
								color: colors.textPrimary,
							} }
						>
							{ __(
								'Products available to AI crawlers',
								'woocommerce-ai-storefront'
							) }
						</h3>
						<p
							style={ {
								margin: '4px 0 0',
								fontSize: '13px',
								color: colors.textSecondary,
							} }
						>
							{ __(
								'Choose which of your products appear in your AI Storefront endpoints.',
								'woocommerce-ai-storefront'
							) }
						</p>
					</div>
				</CardHeader>
				<CardBody style={ { padding: 0 } }>
					{ /*
					   All-row has no detail panel — the mode needs zero
					   configuration (it IS the default, "every product"),
					   so expanding into a panel with a single gray note
					   just added a floating horizontal rule with
					   whitespace above and below it. The count pill +
					   header description already carry the full state
					   (Label: "All published products" / Description:
					   "Every published product… discoverable by AI
					   crawlers" / Pill: "39 products"). Auto-include
					   semantics are inherent in "all" — no merchant
					   needs a separate line to explain it. ModeRow
					   skips its panel render when children is falsy.
					*/ }
					<ModeRow
						value={ UI_ROWS.ALL }
						selected={ uiRow }
						name="ai_syndication_mode"
						label={ __(
							'All published products',
							'woocommerce-ai-storefront'
						) }
						description={ MODE_DESCRIPTIONS[ UI_ROWS.ALL ] }
						badgeLabel={ allBadge }
						onSelect={ setRow }
					/>

					<ModeRow
						value={ UI_ROWS.BY_TAXONOMY }
						selected={ uiRow }
						name="ai_syndication_mode"
						label={ __(
							'Products by category, tag, or brand',
							'woocommerce-ai-storefront'
						) }
						description={ MODE_DESCRIPTIONS[ UI_ROWS.BY_TAXONOMY ] }
						badgeLabel={ taxonomyBadge }
						onSelect={ setRow }
					>
						<div
							style={ {
								padding: '12px 0',
							} }
						>
							{ /*
							   Content-sized (no `isBlock`) so the three
							   segments read as a compact "pick one of N"
							   strip rather than stretched form fields.
							   `isBlock` was tried in an earlier revision
							   but made the control look like row headers
							   spanning the panel width, weakening the
							   segmented-pill affordance. Matches how
							   Gutenberg uses the same component (e.g.
							   alignment toolbar).

							   Styling overrides below (scoped via
							   `.ai-storefront-taxonomy-toggle`):

							   1. Padding: @wordpress/components'
							      ToggleGroupControlOption uses very tight
							      horizontal padding when !isBlock — text
							      sits nearly flush against the selected
							      pill's edges. Widening to 14px gives
							      the selected segment breathing room.

							   2. Selected-state visual treatment (the
							      "elevated white pill on a recessed
							      neutral track" pattern, matching iOS
							      Settings / macOS Finder / Gutenberg's
							      block-toolbar alignment control). The
							      WP default renders a flat black
							      backdrop with no depth cues — no
							      shadow, no border-radius differential,
							      no inset on the track — so the
							      selected state reads as a paint-fill
							      rather than an interactive thumb.
							      Adding (a) an inset shadow + hairline
							      border on the track so it looks
							      pressed in, and (b) a white backdrop
							      with a soft contact shadow + 0.5px
							      ring so the pill looks raised, gives
							      the merchant the two depth signals
							      that "selected" needs to land.

							      Critical: the selected-text styling
							      keys off `[aria-checked="true"]` on
							      the option button rather than the
							      backdrop sibling. The backdrop is
							      presentational; ARIA-state is the
							      single source of truth that visual
							      and screen-reader signals can't drift
							      apart.

							   3. Hover only fires on UNSELECTED options
							      so it doesn't fight the pill, and
							      `:focus-visible` (not `:focus`) gates
							      the keyboard ring — preventing the
							      ring on mouse clicks, which is the
							      Gutenberg standard since WP 6.0.
							*/ }
							<style>{ `
								.ai-storefront-taxonomy-toggle .components-button {
									padding-left: 14px !important;
									padding-right: 14px !important;
								}
								/* Track: recessed neutral surface so the pill reads as floating above it.
								   Targets the wrapping element directly via our scope class — WP
								   components 28.x uses Emotion CSS-in-JS with dynamically-generated
								   class names (no stable .components-toggle-group-control class), so
								   the pre-0.1.10 selectors targeting that name didn't match anything. */
								.ai-storefront-taxonomy-toggle {
									background: rgba( 0, 0, 0, 0.04 ) !important;
									border: 1px solid rgba( 0, 0, 0, 0.08 ) !important;
									box-shadow: inset 0 1px 1px rgba( 0, 0, 0, 0.04 ) !important;
									border-radius: 6px !important;
								}
								/* The "moving pill" is the WP component's :before pseudo-element
								   on the same wrapping div (positioned absolutely, animated via
								   CSS variables). Override the default COLORS.gray[900] black
								   background with white + a soft contact shadow + 0.5px ring,
								   yielding the elevation cue the design needs. */
								.ai-storefront-taxonomy-toggle::before {
									background: #fff !important;
									box-shadow:
										0 1px 2px rgba( 0, 0, 0, 0.12 ),
										0 0 0 0.5px rgba( 0, 0, 0, 0.08 ) !important;
								}
								/* Suppress WP's :focus-within border that uses the admin theme
								   color (vivid pink/magenta on Sunrise, blue on Modern, etc.)
								   so the loud color doesn't compete with the elevated-pill
								   visual signal. Keyboard-only focus indication moves to the
								   selected button below via :focus-visible. */
								.ai-storefront-taxonomy-toggle:focus-within {
									border-color: rgba( 0, 0, 0, 0.08 ) !important;
									box-shadow: inset 0 1px 1px rgba( 0, 0, 0, 0.04 ) !important;
								}
								/* Selected button text. WP defaults to white because the pill
								   was black; the pill is now white, so the text needs to be dark
								   for contrast. Selector keys off [aria-checked="true"] which is
								   stable across WP component versions (ARIA is the contract). */
								.ai-storefront-taxonomy-toggle [aria-checked="true"] {
									color: #1e1e1e !important;
									font-weight: 500;
								}
								/* Unselected hover affordance — only on the [aria-checked="false"]
								   options so it doesn't fight the active pill. */
								.ai-storefront-taxonomy-toggle [aria-checked="false"]:hover {
									color: #1e1e1e !important;
								}
								/* Keyboard-only focus ring on the selected button using the
								   WP admin theme color. :focus-visible (not :focus) prevents
								   the ring from appearing on mouse clicks — the Gutenberg
								   standard since WP 6.0. */
								.ai-storefront-taxonomy-toggle [aria-checked="true"]:focus-visible {
									outline: 2px solid var( --wp-admin-theme-color, #3858e9 );
									outline-offset: 2px;
									border-radius: 5px;
								}
								/* Windows High Contrast mode: ensure the pill carries a
								   visible edge when forced colors strip the box-shadow. */
								@media ( forced-colors: active ) {
									.ai-storefront-taxonomy-toggle::before {
										outline: 1px solid CanvasText !important;
									}
								}
							` }</style>
							<ToggleGroupControl
								__next40pxDefaultSize
								__nextHasNoMarginBottom
								hideLabelFromVision
								className="ai-storefront-taxonomy-toggle"
								label={ __(
									'Taxonomy',
									'woocommerce-ai-storefront'
								) }
								value={ activeTaxonomy }
								onChange={ setTaxonomy }
							>
								<ToggleGroupControlOption
									value={ TAXONOMY_TABS.CATEGORIES }
									label={ __(
										'Categories',
										'woocommerce-ai-storefront'
									) }
								/>
								<ToggleGroupControlOption
									value={ TAXONOMY_TABS.TAGS }
									label={ __(
										'Tags',
										'woocommerce-ai-storefront'
									) }
								/>
								{ supportsBrands && (
									<ToggleGroupControlOption
										value={ TAXONOMY_TABS.BRANDS }
										label={ __(
											'Brands',
											'woocommerce-ai-storefront'
										) }
									/>
								) }
							</ToggleGroupControl>
						</div>

						{ /*
						   Empty-selection warning. Fires when the
						   ENFORCING taxonomy (the one matching
						   `effectiveMode`, not whichever tab the
						   merchant is currently viewing) has zero
						   terms selected — both enforcement gates
						   (Store API filter + `is_product_syndicated()`)
						   hide all products in that state. That's
						   the correct enforcement behavior for "you
						   picked a scoping mode but left it empty,"
						   but it's not obvious from the UI — the
						   picker below just looks like "pick some
						   terms to get started." This Notice spells
						   the consequence out so the merchant doesn't
						   inadvertently save a zero-selection state
						   that hides their catalog from every AI
						   agent.
						   The view/enforce split matters here: a
						   merchant on mode=brands with a selection
						   on disk who clicks the Tags tab to browse
						   must NOT see "No tags selected, products
						   hidden" — nothing has changed on disk,
						   and their brand-scoped catalog is still
						   being enforced. The Notice tracks the
						   enforcing mode so the warning only fires
						   when saving would actually hide the
						   catalog, and the copy names the specific
						   taxonomy that needs action (e.g. "No
						   categories selected…") so a merchant on
						   the Tags tab still gets a clear cue.
						   Same yellow severity as the `selected`
						   panel's "new products not auto-included"
						   warning — consistent treatment for the
						   two modes whose empty/misconfigured states
						   have merchant-visible consequences.
						*/ }
						{ emptyEnforcingSelection && (
							<Notice
								status="warning"
								isDismissible={ false }
								className="ai-syndication-empty-taxonomy-warning"
								// WP's default Notice styling targets
								// admin-banner placement (edge-to-edge at
								// the top of a page). Embedded inside a
								// detail panel, the defaults read as
								// cramped (text hugs the yellow left
								// accent, no separation from content
								// above). Override with internal padding
								// and top margin for card-embedded use.
								style={ {
									margin: '12px 0 0',
									padding: '8px 12px',
								} }
							>
								{ emptyTaxonomyWarning }
							</Notice>
						) }

						{ activeTaxonomy === TAXONOMY_TABS.CATEGORIES && (
							<TaxonomyPicker
								items={ categories }
								filtered={ filteredCategories }
								selectedIds={ selectedCategories }
								selectedTokens={ selectedCategoryTokens }
								search={ categorySearch }
								onSearch={ setCategorySearch }
								onToggle={ toggleCategory }
								onSelectAll={ () => {
									// Bulk-select mirrors the per-term
									// toggle's commit semantics: populating
									// the array is a deliberate act of
									// scoping to this taxonomy, so write
									// both the selection AND the mode
									// atomically. Without this, a merchant
									// could "Select all" from an unrelated
									// mode (e.g. `all`) and walk away
									// thinking they'd scoped to categories
									// — but the server would still enforce
									// `all`.
									const ids = categories.map(
										( cat ) => cat.id
									);
									const changes = {
										selected_categories: ids,
									};
									if ( ids.length > 0 ) {
										changes.product_selection_mode =
											MODES.BY_TAXONOMY;
									}
									onChange( changes );
								} }
								onClear={ () =>
									onChange( { selected_categories: [] } )
								}
								isLoading={ isLoadingCategories }
								hasError={ hasCategoriesError }
								searchPlaceholder={ __(
									'Filter categories\u2026',
									'woocommerce-ai-storefront'
								) }
								emptyMatchLabel={ __(
									'No categories match your filter.',
									'woocommerce-ai-storefront'
								) }
								emptyLabel={ __(
									"You haven't created any categories yet. Create them in Products \u2192 Categories.",
									'woocommerce-ai-storefront'
								) }
								errorLabel={ __(
									"Couldn't load your categories right now. If you have categories configured, refresh this page to retry.",
									'woocommerce-ai-storefront'
								) }
								disclosure={ __(
									'Auto-includes future products added to these categories.',
									'woocommerce-ai-storefront'
								) }
							/>
						) }

						{ activeTaxonomy === TAXONOMY_TABS.TAGS && (
							<TaxonomyPicker
								items={ tags }
								filtered={ filteredTags }
								selectedIds={ selectedTags }
								selectedTokens={ selectedTagTokens }
								tokenVariant="tag"
								search={ tagSearch }
								onSearch={ setTagSearch }
								onToggle={ toggleTag }
								onSelectAll={ () => {
									// Bulk-select mirrors the toggle's
									// mode-commit semantics; see
									// toggleCategory's `onSelectAll` for
									// full rationale.
									const ids = tags.map( ( tag ) => tag.id );
									const changes = { selected_tags: ids };
									if ( ids.length > 0 ) {
										changes.product_selection_mode =
											MODES.BY_TAXONOMY;
									}
									onChange( changes );
								} }
								onClear={ () =>
									onChange( { selected_tags: [] } )
								}
								isLoading={ isLoadingTags }
								hasError={ hasTagsError }
								searchPlaceholder={ __(
									'Filter tags (e.g. summer, sale)\u2026',
									'woocommerce-ai-storefront'
								) }
								emptyMatchLabel={ __(
									'No tags match your filter.',
									'woocommerce-ai-storefront'
								) }
								emptyLabel={ __(
									"You haven't created any tags yet. Add tags on a product's edit screen.",
									'woocommerce-ai-storefront'
								) }
								errorLabel={ __(
									"Couldn't load your tags right now. If you have tags configured, refresh this page to retry.",
									'woocommerce-ai-storefront'
								) }
								disclosure={ createInterpolateElement(
									__(
										'Products are included when they have <strong>any</strong> of the selected tags. Auto-includes future products that match.',
										'woocommerce-ai-storefront'
									),
									{ strong: <strong /> }
								) }
							/>
						) }

						{ activeTaxonomy === TAXONOMY_TABS.BRANDS &&
							supportsBrands && (
								<TaxonomyPicker
									items={ brands }
									filtered={ filteredBrands }
									selectedIds={ selectedBrands }
									selectedTokens={ selectedBrandTokens }
									search={ brandSearch }
									onSearch={ setBrandSearch }
									onToggle={ toggleBrand }
									onSelectAll={ () => {
										// Bulk-select mirrors the toggle's
										// mode-commit semantics; see
										// toggleCategory's `onSelectAll` for
										// full rationale.
										const ids = brands.map(
											( brand ) => brand.id
										);
										const changes = {
											selected_brands: ids,
										};
										if ( ids.length > 0 ) {
											changes.product_selection_mode =
												MODES.BY_TAXONOMY;
										}
										onChange( changes );
									} }
									onClear={ () =>
										onChange( { selected_brands: [] } )
									}
									isLoading={ isLoadingBrands }
									hasError={ hasBrandsError }
									searchPlaceholder={ __(
										'Filter brands (e.g. Adidas, Nike)\u2026',
										'woocommerce-ai-storefront'
									) }
									emptyMatchLabel={ __(
										'No brands match your filter.',
										'woocommerce-ai-storefront'
									) }
									emptyLabel={ __(
										"You haven't created any brands yet. Add brands in Products \u2192 Brands.",
										'woocommerce-ai-storefront'
									) }
									errorLabel={ __(
										"Couldn't load your brands right now. If you have brands configured, refresh this page to retry.",
										'woocommerce-ai-storefront'
									) }
									disclosure={ createInterpolateElement(
										__(
											'Products are included when they belong to <strong>any</strong> of the selected brands. Auto-includes future products that match.',
											'woocommerce-ai-storefront'
										),
										{ strong: <strong /> }
									) }
								/>
							) }
					</ModeRow>

					<ModeRow
						value={ UI_ROWS.SELECTED }
						selected={ uiRow }
						name="ai_syndication_mode"
						label={ __(
							'Specific products only',
							'woocommerce-ai-storefront'
						) }
						description={ MODE_DESCRIPTIONS[ UI_ROWS.SELECTED ] }
						badgeLabel={ selectedBadge }
						onSelect={ setRow }
						isLast
					>
						<div style={ { paddingTop: '14px' } }>
							<SelectedTokens
								items={ selectedProductTokens }
								onRemove={ toggleProduct }
							/>
							<SearchControl
								__nextHasNoMarginBottom
								value={ productSearch }
								onChange={ setProductSearch }
								placeholder={ __(
									'Search products\u2026',
									'woocommerce-ai-storefront'
								) }
							/>
							{ selectedProducts.length > 0 && (
								<div
									style={ {
										display: 'flex',
										gap: '12px',
										margin: '8px 0',
									} }
								>
									<Button
										variant="link"
										onClick={ () =>
											onChange( {
												selected_products: [],
											} )
										}
										style={ {
											fontSize: '12px',
											padding: 0,
											minHeight: 'auto',
										} }
									>
										{ __(
											'Clear selection',
											'woocommerce-ai-storefront'
										) }
									</Button>
								</div>
							) }
							{ isLoadingProducts ? (
								<div
									style={ {
										padding: '24px',
										textAlign: 'center',
									} }
								>
									<Spinner />
								</div>
							) : (
								<div
									style={ {
										maxHeight: '260px',
										overflow: 'auto',
										background: colors.surface,
										border: `1px solid ${ colors.borderSubtle }`,
										borderRadius: '3px',
										padding: '4px 16px',
									} }
								>
									{ products.length === 0 && (
										<p
											style={ {
												color: colors.textMuted,
												fontSize: '13px',
												textAlign: 'center',
												padding: '16px 0',
												margin: 0,
											} }
										>
											{ productSearch
												? __(
														'No products found. Try a different search.',
														'woocommerce-ai-storefront'
												  )
												: __(
														'Start typing to search your products.',
														'woocommerce-ai-storefront'
												  ) }
										</p>
									) }
									{ products.map( ( product, index ) => (
										<div
											key={ product.id }
											style={ {
												padding: '6px 0',
												borderBottom:
													index < products.length - 1
														? `1px solid ${ colors.borderSubtle }`
														: 'none',
											} }
										>
											<CheckboxControl
												label={ sprintf(
													/* translators: %1$s: product name, %2$s: price */
													__(
														'%1$s \u2014 %2$s',
														'woocommerce-ai-storefront'
													),
													decodeEntities(
														product.name
													),
													decodeEntities(
														product.price
													)
												) }
												checked={ selectedProducts.includes(
													product.id
												) }
												onChange={ () =>
													toggleProduct( product.id )
												}
												__nextHasNoMarginBottom
											/>
										</div>
									) ) }
								</div>
							) }
							{ /*
							   No warning Notice here. Prior versions
							   rendered a yellow "New products are not
							   auto-included" banner at the bottom of
							   this panel, but the behavior IS the mode:
							   "hand-pick individual products" means
							   hand-picking — auto-inclusion would
							   defeat the semantic. The mode's header
							   description already says "New products
							   are not auto-included" as part of its
							   explanation, so a yellow warning
							   repeated the same information at higher
							   severity than warranted. Removed to
							   reduce visual noise in a section whose
							   purpose is already clear from the label.
							*/ }
						</div>
					</ModeRow>
				</CardBody>
				<CardFooter>
					<div
						style={ {
							display: 'flex',
							justifyContent: 'space-between',
							alignItems: 'center',
							gap: '16px',
							flexWrap: 'wrap',
							width: '100%',
						} }
					>
						<div>
							<span
								style={ {
									fontSize: '12px',
									fontWeight: '600',
									color: colors.textSecondary,
									textTransform: 'uppercase',
									letterSpacing: '0.4px',
									marginRight: '8px',
								} }
							>
								{ __(
									'Included fields',
									'woocommerce-ai-storefront'
								) }
							</span>
							<span
								style={ {
									display: 'inline-flex',
									flexWrap: 'wrap',
									gap: '6px',
								} }
							>
								{ getIncludedFields().map( ( field ) => (
									<span
										key={ field.key }
										style={ {
											fontSize: '12px',
											background: colors.surface,
											border: `1px solid ${ colors.borderSubtle }`,
											padding: '2px 10px',
											borderRadius: '12px',
											color: colors.textPrimary,
										} }
									>
										{ field.label }
									</span>
								) ) }
							</span>
						</div>
						{ /*
						   No right-side deep-link in this footer. The
						   Discovery tab is the canonical surface for
						   endpoint URLs + per-endpoint testing info,
						   so a second entry point here would just
						   duplicate that tab's job. The footer now
						   carries the one fact it uniquely conveys —
						   the set of fields agents receive — and
						   nothing else.
						*/ }
					</div>
				</CardFooter>
			</Card>

			{ /*
				Page-level Save footer. Matches the Overview tab
				convention and WP admin settings screens generally
				(Settings → General, Writing, Reading, every WC
				Settings tab) — one save at the bottom, not per-card.
			*/ }
			<div
				style={ {
					marginTop: '24px',
					textAlign: 'right',
				} }
			>
				<Button
					variant="primary"
					isBusy={ isSaving }
					disabled={ isSaving }
					onClick={ onSave }
				>
					{ isSaving
						? __( 'Saving\u2026', 'woocommerce-ai-storefront' )
						: __( 'Save changes', 'woocommerce-ai-storefront' ) }
				</Button>
			</div>
		</div>
	);
};

/**
 * Shared selection UI for one taxonomy (categories, tags, or brands).
 *
 * Presents: a `SelectedTokens` chip list at the top (when a selection
 * exists), a SearchControl when the vocabulary is large enough to
 * warrant filtering (> 8 terms), Select-all / Clear action links, and
 * a scrollable CheckboxControl list of terms.
 *
 * Pulled out into its own component because the three taxonomy modes
 * render the same UI with different data + labels; inlining three
 * copies in the parent would obscure the shared structure.
 *
 * @param {Object}                                             root0                   Component props.
 * @param {Array}                                              root0.items             All terms for this taxonomy.
 * @param {Array}                                              root0.filtered          Terms matching the current search filter.
 * @param {number[]}                                           root0.selectedIds       Currently-selected term IDs.
 * @param {Array}                                              root0.selectedTokens    Term objects for chips.
 * @param {string}                                             [root0.tokenVariant]    Passed to SelectedTokens ('tag' for pill shape).
 * @param {string}                                             root0.search            Current search string.
 * @param {Function}                                           root0.onSearch          Updates the search string.
 * @param {Function}                                           root0.onToggle          Toggles one term's selection.
 * @param {Function}                                           root0.onSelectAll       Selects every term.
 * @param {Function}                                           root0.onClear           Clears the selection.
 * @param {boolean}                                            root0.isLoading         Pending fetch spinner.
 * @param {boolean}                                            [root0.hasError]        True when the last fetch failed — renders `errorLabel` in a yellow Notice instead of `emptyLabel`.
 * @param {string}                                             root0.searchPlaceholder Placeholder for the SearchControl.
 * @param {string}                                             root0.emptyMatchLabel   Shown when the filter returns no results.
 * @param {string}                                             root0.emptyLabel        Shown when the fetch succeeded but no terms exist for this taxonomy.
 * @param {string}                                             [root0.errorLabel]      Shown when `hasError` is true; distinct from `emptyLabel` so "merchant has none" and "we couldn't fetch" read differently.
 * @param {JSX.Element|JSX.Element[]|string|number|null|false} root0.disclosure
 *                                                                                     Footer disclosure text (accepts inline strong via
 *                                                                                     createInterpolateElement).
 */
const TaxonomyPicker = ( {
	items,
	filtered,
	selectedIds,
	selectedTokens,
	tokenVariant,
	search,
	onSearch,
	onToggle,
	onSelectAll,
	onClear,
	isLoading,
	hasError,
	searchPlaceholder,
	emptyMatchLabel,
	emptyLabel,
	// Fallback so a future caller setting `hasError` without
	// providing `errorLabel` renders something useful rather than
	// a blank Notice. Generic copy ("Unable to load items.") is
	// deliberately neutral — every current call site passes a
	// taxonomy-specific errorLabel, so this default only fires in
	// a coding-slip scenario where having ANY text beats silence.
	errorLabel = __( 'Unable to load items.', 'woocommerce-ai-storefront' ),
	disclosure,
} ) => {
	const allSelected =
		items.length > 0 &&
		items.every( ( item ) => selectedIds.includes( item.id ) );
	const noneSelected = selectedIds.length === 0;
	const showSearch = ! isLoading && ! hasError && items.length > 8;

	return (
		<>
			<SelectedTokens
				items={ selectedTokens }
				onRemove={ onToggle }
				variant={ tokenVariant }
			/>

			{ showSearch && (
				<SearchControl
					__nextHasNoMarginBottom
					value={ search }
					onChange={ onSearch }
					placeholder={ searchPlaceholder }
				/>
			) }

			{ ! isLoading && ! hasError && items.length > 0 && (
				<div
					style={ {
						display: 'flex',
						gap: '12px',
						margin: showSearch ? '8px 0 8px' : '0 0 8px',
					} }
				>
					<Button
						variant="link"
						disabled={ allSelected }
						onClick={ onSelectAll }
						style={ {
							fontSize: '12px',
							padding: 0,
							minHeight: 'auto',
						} }
					>
						{ __( 'Select all', 'woocommerce-ai-storefront' ) }
					</Button>
					<Button
						variant="link"
						disabled={ noneSelected }
						onClick={ onClear }
						style={ {
							fontSize: '12px',
							padding: 0,
							minHeight: 'auto',
						} }
					>
						{ __( 'Clear selection', 'woocommerce-ai-storefront' ) }
					</Button>
				</div>
			) }

			{ /*
			   Three mutually exclusive render branches for the
			   terms list area. Flat conditionals (not a nested
			   ternary) so eslint's no-nested-ternary rule stays
			   happy and each branch reads independently.
			*/ }
			{ isLoading && (
				<div
					style={ {
						padding: '24px',
						textAlign: 'center',
					} }
				>
					<Spinner />
				</div>
			) }
			{ ! isLoading && hasError && (
				/*
				  Fetch failed (network, auth, unexpected response
				  shape). Render a yellow Notice rather than the
				  `items.length === 0` branch's "you haven't
				  created any X yet" emptyLabel, because we don't
				  know that to be true — we only know we couldn't
				  load the list. Misleading copy would nudge
				  merchants who do have terms toward a pointless
				  "go create some" workflow.
				*/
				<Notice
					status="warning"
					isDismissible={ false }
					className="ai-syndication-taxonomy-fetch-error"
				>
					{ errorLabel }
				</Notice>
			) }
			{ ! isLoading && ! hasError && (
				<div
					style={ {
						maxHeight: '260px',
						overflow: 'auto',
						background: colors.surface,
						border: `1px solid ${ colors.borderSubtle }`,
						borderRadius: '3px',
						padding: '4px 16px',
					} }
				>
					{ items.length === 0 && (
						<p
							style={ {
								color: colors.textMuted,
								fontSize: '13px',
								textAlign: 'center',
								padding: '16px 0',
								margin: 0,
							} }
						>
							{ emptyLabel }
						</p>
					) }
					{ items.length > 0 && filtered.length === 0 && search && (
						<p
							style={ {
								color: colors.textMuted,
								fontSize: '13px',
								textAlign: 'center',
								padding: '16px 0',
								margin: 0,
							} }
						>
							{ emptyMatchLabel }
						</p>
					) }
					{ filtered.map( ( item, index ) => (
						<div
							key={ item.id }
							style={ {
								padding: '6px 0',
								borderBottom:
									index < filtered.length - 1
										? `1px solid ${ colors.borderSubtle }`
										: 'none',
							} }
						>
							<CheckboxControl
								label={ sprintf(
									/* translators: %1$s: term name, %2$d: product count */
									__(
										'%1$s (%2$d)',
										'woocommerce-ai-storefront'
									),
									decodeEntities( item.name ),
									item.count
								) }
								checked={ selectedIds.includes( item.id ) }
								onChange={ () => onToggle( item.id ) }
								__nextHasNoMarginBottom
							/>
						</div>
					) ) }
				</div>
			) }

			<Disclosure>{ disclosure }</Disclosure>
		</>
	);
};

/**
 * Footer-of-panel disclosure line — used by 'all' and taxonomy modes
 * for their auto-inclusion + ANY-match explanations. The 'selected'
 * mode uses a `<Notice status="warning">` instead because its
 * disclosure is an actual behavioral surprise, not a neutral fact.
 *
 * Accepts rich children (not just strings) so taxonomy disclosures
 * built via createInterpolateElement can inline <strong> around the
 * "any" semantics without dropping out of the styled paragraph.
 *
 * @param {Object}                                             root0
 *                                                                            Component props.
 * @param {JSX.Element|JSX.Element[]|string|number|null|false} root0.children
 *                                                                            Disclosure text or interpolated React node.
 */
const Disclosure = ( { children } ) => (
	<p
		style={ {
			margin: '14px 0 0',
			paddingTop: '12px',
			borderTop: `1px solid ${ colors.borderSubtle }`,
			color: colors.textMuted,
			fontSize: '12px',
		} }
	>
		{ children }
	</p>
);

export default ProductSelection;
