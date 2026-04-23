import { useState, useEffect, useMemo } from '@wordpress/element';
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
} from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { decodeEntities } from '@wordpress/html-entities';
import apiFetch from '@wordpress/api-fetch';
import { colors } from './tokens';

const MODES = {
	ALL: 'all',
	CATEGORIES: 'categories',
	SELECTED: 'selected',
};

// Short, action-oriented descriptions surfaced under each radio row's
// label. The longer "what this means" narrative lives in the detail
// panel heading + disclosure so the collapsed row stays scannable.
const MODE_DESCRIPTIONS = {
	[ MODES.ALL ]: __(
		'Every published product in your store is discoverable by AI crawlers.',
		'woocommerce-ai-storefront'
	),
	[ MODES.CATEGORIES ]: __(
		'Share only products within specific WooCommerce categories.',
		'woocommerce-ai-storefront'
	),
	[ MODES.SELECTED ]: __(
		'Hand-pick individual products. New products are not auto-included.',
		'woocommerce-ai-storefront'
	),
};

// Spec-aligned list of fields our endpoints serialize per product.
// Surfaced in the shared footer as "Included fields" chips so merchants
// see at a glance what each AI agent receives. If the extension schema
// (see class-wc-ai-storefront-ucp-rest-controller.php
// handle_extension_schema) grows, update this list — the schema itself
// is the source of truth for on-the-wire contents, this is the
// human-readable summary.
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
 * @param {Object}  root0          Component props.
 * @param {string}  root0.label    Text content (pre-formatted).
 * @param {boolean} root0.selected Whether the parent row is selected.
 */
const ModeBadge = ( { label, selected } ) => (
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

/**
 * Token list showing currently-selected items with remove buttons.
 * Reused from the pre-rewrite implementation — same behavior, same
 * keyboard/screen-reader contract. Gives the selected-mode detail
 * panels a "here's what you've picked" summary at a glance.
 *
 * @param {Object}   root0          Component props.
 * @param {Array}    root0.items    Selected items with { id, name }.
 * @param {Function} root0.onRemove Callback when an item is removed.
 */
const SelectedTokens = ( { items, onRemove } ) => {
	if ( items.length === 0 ) {
		return null;
	}

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
						borderRadius: '3px',
						padding: '3px 6px 3px 10px',
						fontSize: '12px',
						color: colors.textPrimary,
						lineHeight: '1.4',
					} }
				>
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
 * Sample grid for the 'all' mode detail panel — 6 products with
 * thumbnails + price, fetched from the Store API (same surface AI
 * agents hit), with the total published-product count read from the
 * `X-WP-Total` response header.
 *
 * Store API is preferred over the admin product-search endpoint here
 * because it's the exact surface agents see — visibility filters
 * (catalog visibility, out-of-stock exclusion) match what gets
 * syndicated, making the grid a live self-demonstration of "this is
 * what AI crawlers are fetching right now." Admin-search returns a
 * merchant-view list that may include hidden products.
 *
 * Cached in component state for the lifetime of the tab visit —
 * re-fetches only on mount.
 */
const SamplePreview = () => {
	const [ products, setProducts ] = useState( [] );
	const [ total, setTotal ] = useState( null );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ hasError, setHasError ] = useState( false );

	useEffect( () => {
		let cancelled = false;
		setIsLoading( true );
		setHasError( false );

		// `parse: false` → gives us the raw Response so we can read
		// `X-WP-Total` for the total published-product count. apiFetch's
		// default parse mode discards headers.
		apiFetch( {
			path: '/wc/store/v1/products?per_page=6',
			parse: false,
		} )
			.then( async ( response ) => {
				const body = await response.json();
				if ( cancelled ) {
					return;
				}
				// `X-WP-Total` may be absent (some reverse-proxy
				// configurations strip it from cached responses) or
				// unparseable. Treat anything that doesn't parse to
				// a finite number as "unknown" — the UI handles a
				// `null` total by omitting the count pill, which is
				// far better than rendering `NaN`.
				const totalHeader = response.headers.get( 'X-WP-Total' );
				const parsedTotal =
					totalHeader !== null ? parseInt( totalHeader, 10 ) : NaN;
				setProducts( Array.isArray( body ) ? body : [] );
				setTotal( Number.isFinite( parsedTotal ) ? parsedTotal : null );
				setIsLoading( false );
			} )
			.catch( () => {
				if ( cancelled ) {
					return;
				}
				setHasError( true );
				setIsLoading( false );
			} );

		return () => {
			cancelled = true;
		};
	}, [] );

	if ( isLoading ) {
		return (
			<div style={ { padding: '32px 0', textAlign: 'center' } }>
				<Spinner />
			</div>
		);
	}

	if ( hasError || products.length === 0 ) {
		// Fail-closed fallback: empty-state message rather than a
		// broken grid. Agents still get the catalog correctly — this
		// preview is purely diagnostic, so a temporary Store API hiccup
		// shouldn't break the whole tab.
		return (
			<p
				style={ {
					color: colors.textMuted,
					fontSize: '13px',
					padding: '16px 0',
					margin: 0,
				} }
			>
				{ __(
					'Sample preview unavailable. Your products are still being shared — visit the Endpoints tab to verify.',
					'woocommerce-ai-storefront'
				) }
			</p>
		);
	}

	return (
		<>
			<div
				style={ {
					display: 'flex',
					justifyContent: 'space-between',
					alignItems: 'baseline',
					marginBottom: '10px',
				} }
			>
				<h5
					style={ {
						margin: 0,
						fontSize: '12px',
						fontWeight: '600',
						color: colors.textSecondary,
						textTransform: 'uppercase',
						letterSpacing: '0.4px',
					} }
				>
					{ __(
						"Sample of what's shared",
						'woocommerce-ai-storefront'
					) }
				</h5>
				{ total !== null && (
					<Button
						variant="link"
						href="edit.php?post_type=product"
						style={ { fontSize: '13px' } }
					>
						{ sprintf(
							/* translators: %s: total product count, formatted via toLocaleString using the browser locale. */
							__(
								'View all %s in Products \u2192',
								'woocommerce-ai-storefront'
							),
							total.toLocaleString()
						) }
					</Button>
				) }
			</div>

			<div
				style={ {
					display: 'grid',
					gridTemplateColumns: 'repeat(6, 1fr)',
					gap: '10px',
				} }
			>
				{ products.map( ( product ) => (
					<SamplePreviewTile key={ product.id } product={ product } />
				) ) }
			</div>
		</>
	);
};

/**
 * One tile in the sample grid. Store API gives us `images[0].thumbnail`
 * as a fully-qualified URL; we fall back to a neutral placeholder when
 * a product has no image (gracefully handles merchants who haven't
 * populated product media — the grid still reads as populated).
 *
 * Price field from Store API comes as `price_html` — already localized,
 * currency-formatted, and handles sale prices, variation ranges, and
 * on-sale markup correctly. When `price_html` is absent we render no
 * price line at all (the tile shows just the name + thumbnail) rather
 * than trying to reconstruct from the minor-unit `prices.price` value,
 * which would require separate currency-symbol + locale wiring.
 *
 * @param {Object} root0         Component props.
 * @param {Object} root0.product Store API product payload.
 */
const SamplePreviewTile = ( { product } ) => {
	const thumbnail = product.images?.[ 0 ]?.thumbnail || '';
	const name = decodeEntities( product.name || '' );
	const price = product.price_html
		? decodeEntities( product.price_html )
		: '';

	return (
		<div
			style={ {
				border: `1px solid ${ colors.borderSubtle }`,
				borderRadius: '3px',
				overflow: 'hidden',
				background: colors.surface,
			} }
		>
			<div
				style={ {
					aspectRatio: '1 / 1',
					background: colors.surfaceSubtle,
					display: 'flex',
					alignItems: 'center',
					justifyContent: 'center',
					overflow: 'hidden',
				} }
			>
				{ thumbnail ? (
					<img
						src={ thumbnail }
						alt=""
						style={ {
							width: '100%',
							height: '100%',
							objectFit: 'cover',
						} }
					/>
				) : (
					<span
						aria-hidden="true"
						style={ {
							fontSize: '28px',
							color: colors.textMuted,
						} }
					>
						{ '\u25A1' }
					</span>
				) }
			</div>
			<div style={ { padding: '6px 8px 8px' } }>
				<div
					style={ {
						fontSize: '12px',
						fontWeight: '600',
						color: colors.textPrimary,
						overflow: 'hidden',
						textOverflow: 'ellipsis',
						whiteSpace: 'nowrap',
					} }
					title={ name }
				>
					{ name }
				</div>
				{ price && (
					<div
						style={ {
							fontSize: '11px',
							color: colors.textMuted,
							marginTop: '2px',
						} }
						/* price_html is already sanitized (wp_strip_all_tags
						   on the server, decodeEntities here), so innerHTML
						   isn't needed — plain text rendering is enough. */
					>
						{ price.replace( /<[^>]+>/g, '' ) }
					</div>
				) }
			</div>
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
 * Selected state renders the children (detail panel) below the
 * label row; unselected state hides them entirely (not just
 * visually) so the DOM stays small and assistive tech doesn't read
 * hidden content.
 *
 * @param {Object}   root0             Component props.
 * @param {string}   root0.value       This option's value.
 * @param {string}   root0.selected    Currently-selected option value.
 * @param {string}   root0.name        Radio group name.
 * @param {string}   root0.label       Option label (bold).
 * @param {string}   root0.description Option description (muted).
 * @param {string}   root0.badgeLabel  Text for the right-aligned badge.
 * @param {Function} root0.onSelect    Called with this option's value.
 * @param {Node}     root0.children    Detail panel content (rendered when selected).
 * @param {boolean}  root0.isLast      Suppresses the label's bottom border regardless of selection state.
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
					padding: '14px 20px',
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
			{ isSelected && (
				<div
					style={ {
						padding: '0 20px 18px 50px',
						background: '#f6fbfd',
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

/**
 * Detail-panel summary line. Every mode's panel opens with one of
 * these ("Currently sharing X …") so the three modes feel visually
 * consistent despite having different selection UIs below.
 *
 * @param {Object} root0          Component props.
 * @param {string} root0.children Summary text.
 */
const PanelHeading = ( { children } ) => (
	<h4
		style={ {
			fontSize: '14px',
			fontWeight: '600',
			color: colors.textPrimary,
			margin: '14px 0',
		} }
	>
		{ children }
	</h4>
);

const ProductSelection = ( { settings, onChange, onSave, isSaving } ) => {
	const [ categories, setCategories ] = useState( [] );
	const [ products, setProducts ] = useState( [] );
	const [ productSearch, setProductSearch ] = useState( '' );
	const [ categorySearch, setCategorySearch ] = useState( '' );
	const [ isLoadingCategories, setIsLoadingCategories ] = useState( false );
	const [ isLoadingProducts, setIsLoadingProducts ] = useState( false );

	// Load categories eagerly on mount (cheap, small list) so the
	// categories radio row can show an accurate count even when
	// unselected.
	useEffect( () => {
		setIsLoadingCategories( true );
		apiFetch( {
			path: '/wc/v3/ai-storefront/admin/search/categories',
		} )
			// Guard against unexpected response shapes (HTML error
			// page, null, WP_Error envelope). Downstream code calls
			// `categories.filter(...)` and `categories.every(...)` —
			// a non-array would crash the settings tab entirely.
			.then( ( result ) =>
				setCategories( Array.isArray( result ) ? result : [] )
			)
			.catch( () => {} )
			.finally( () => setIsLoadingCategories( false ) );
	}, [] );

	// Product search only runs when the merchant is actively in the
	// 'selected' mode — no point loading a product list they won't see.
	useEffect( () => {
		if ( settings.product_selection_mode !== MODES.SELECTED ) {
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
	}, [ productSearch, settings.product_selection_mode ] );

	const selectedCategories = useMemo(
		() => settings.selected_categories || [],
		[ settings.selected_categories ]
	);
	const selectedProducts = useMemo(
		() => settings.selected_products || [],
		[ settings.selected_products ]
	);
	const mode = settings.product_selection_mode || MODES.ALL;

	const toggleCategory = ( catId ) => {
		const updated = selectedCategories.includes( catId )
			? selectedCategories.filter( ( id ) => id !== catId )
			: [ ...selectedCategories, catId ];
		onChange( { selected_categories: updated } );
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

	const selectedCategoryTokens = useMemo( () => {
		return categories.filter( ( cat ) =>
			selectedCategories.includes( cat.id )
		);
	}, [ categories, selectedCategories ] );

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

	// Badge labels — per-mode, pluralized, reflect the current
	// configuration. The "all" badge needs the total product count;
	// SamplePreview exposes it via X-WP-Total but is only mounted in
	// the expanded state, so we also fetch a lightweight total here
	// for the collapsed-row display. Three states:
	//   - null     → still loading → show "Loading…"
	//   - 'error'  → fetch failed or response was unusable → show
	//                the generic label without a count ("Products")
	//   - number   → finite integer → format + render
	// Sentinel-string is cheaper than a separate error ref and the
	// three-state union lines up with how the badge is rendered
	// below.
	const [ totalPublished, setTotalPublished ] = useState( null );
	useEffect( () => {
		let cancelled = false;
		apiFetch( {
			path: '/wc/store/v1/products?per_page=1',
			parse: false,
		} )
			.then( ( response ) => {
				if ( cancelled ) {
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

	const categoriesBadge = sprintf(
		/* translators: %d: number of selected categories. */
		_n(
			'%d category',
			'%d categories',
			selectedCategories.length,
			'woocommerce-ai-storefront'
		),
		selectedCategories.length
	);

	const selectedBadge = sprintf(
		/* translators: %d: number of selected products. */
		_n(
			'%d selected',
			'%d selected',
			selectedProducts.length,
			'woocommerce-ai-storefront'
		),
		selectedProducts.length
	);

	const allCategoriesSelected =
		categories.length > 0 &&
		categories.every( ( cat ) => selectedCategories.includes( cat.id ) );
	const noCategoriesSelected = selectedCategories.length === 0;

	const setMode = ( value ) => onChange( { product_selection_mode: value } );

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
					<ModeRow
						value={ MODES.ALL }
						selected={ mode }
						name="ai_syndication_mode"
						label={ __(
							'All published products',
							'woocommerce-ai-storefront'
						) }
						description={ MODE_DESCRIPTIONS[ MODES.ALL ] }
						badgeLabel={ allBadge }
						onSelect={ setMode }
					>
						<PanelHeading>
							{ typeof totalPublished === 'number'
								? sprintf(
										/* translators: %s: total product count, formatted via toLocaleString using the browser locale. */
										__(
											'Currently sharing all %s published products',
											'woocommerce-ai-storefront'
										),
										totalPublished.toLocaleString()
								  )
								: __(
										'Currently sharing all published products',
										'woocommerce-ai-storefront'
								  ) }
						</PanelHeading>
						<SamplePreview />
						<Disclosure>
							{ __(
								"Auto-includes new products as they're published.",
								'woocommerce-ai-storefront'
							) }
						</Disclosure>
					</ModeRow>

					<ModeRow
						value={ MODES.CATEGORIES }
						selected={ mode }
						name="ai_syndication_mode"
						label={ __(
							'Products in selected categories',
							'woocommerce-ai-storefront'
						) }
						description={ MODE_DESCRIPTIONS[ MODES.CATEGORIES ] }
						badgeLabel={ categoriesBadge }
						onSelect={ setMode }
					>
						<PanelHeading>
							{ sprintf(
								/* translators: %d: category count. */
								_n(
									'Currently sharing %d category',
									'Currently sharing %d categories',
									selectedCategories.length,
									'woocommerce-ai-storefront'
								),
								selectedCategories.length
							) }
						</PanelHeading>
						<SelectedTokens
							items={ selectedCategoryTokens }
							onRemove={ toggleCategory }
						/>
						{ ! isLoadingCategories && categories.length > 8 && (
							<SearchControl
								__nextHasNoMarginBottom
								value={ categorySearch }
								onChange={ setCategorySearch }
								placeholder={ __(
									'Filter categories\u2026',
									'woocommerce-ai-storefront'
								) }
							/>
						) }
						{ ! isLoadingCategories && categories.length > 0 && (
							<div
								style={ {
									display: 'flex',
									gap: '12px',
									margin:
										categories.length > 8
											? '8px 0 8px'
											: '0 0 8px',
								} }
							>
								<Button
									variant="link"
									disabled={ allCategoriesSelected }
									onClick={ () =>
										onChange( {
											selected_categories: categories.map(
												( cat ) => cat.id
											),
										} )
									}
									style={ {
										fontSize: '12px',
										padding: 0,
										minHeight: 'auto',
									} }
								>
									{ __(
										'Select all',
										'woocommerce-ai-storefront'
									) }
								</Button>
								<Button
									variant="link"
									disabled={ noCategoriesSelected }
									onClick={ () =>
										onChange( {
											selected_categories: [],
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
						{ isLoadingCategories ? (
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
								{ filteredCategories.length === 0 &&
									categorySearch && (
										<p
											style={ {
												color: colors.textMuted,
												fontSize: '13px',
												textAlign: 'center',
												padding: '16px 0',
												margin: 0,
											} }
										>
											{ __(
												'No categories match your filter.',
												'woocommerce-ai-storefront'
											) }
										</p>
									) }
								{ filteredCategories.map( ( cat, index ) => (
									<div
										key={ cat.id }
										style={ {
											padding: '6px 0',
											borderBottom:
												index <
												filteredCategories.length - 1
													? `1px solid ${ colors.borderSubtle }`
													: 'none',
										} }
									>
										<CheckboxControl
											label={ sprintf(
												/* translators: %1$s: category name, %2$d: product count */
												__(
													'%1$s (%2$d)',
													'woocommerce-ai-storefront'
												),
												decodeEntities( cat.name ),
												cat.count
											) }
											checked={ selectedCategories.includes(
												cat.id
											) }
											onChange={ () =>
												toggleCategory( cat.id )
											}
											__nextHasNoMarginBottom
										/>
									</div>
								) ) }
							</div>
						) }
						<Disclosure>
							{ __(
								'Auto-includes future products added to these categories.',
								'woocommerce-ai-storefront'
							) }
						</Disclosure>
					</ModeRow>

					<ModeRow
						value={ MODES.SELECTED }
						selected={ mode }
						name="ai_syndication_mode"
						label={ __(
							'Specific products only',
							'woocommerce-ai-storefront'
						) }
						description={ MODE_DESCRIPTIONS[ MODES.SELECTED ] }
						badgeLabel={ selectedBadge }
						onSelect={ setMode }
						isLast
					>
						<PanelHeading>
							{ sprintf(
								/* translators: %d: selected product count. */
								_n(
									'Currently sharing %d product',
									'Currently sharing %d products',
									selectedProducts.length,
									'woocommerce-ai-storefront'
								),
								selectedProducts.length
							) }
						</PanelHeading>
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
												decodeEntities( product.name ),
												decodeEntities( product.price )
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
						   Warning severity — unique to this mode. The other
						   two modes have benign "auto-includes …" lines; this
						   one has a real behavioral surprise ("new products
						   are NOT auto-included") that justifies a yellow
						   notice. Using the Notice component (not a styled
						   div) so screen readers announce the severity.
						*/ }
						<Notice
							status="warning"
							isDismissible={ false }
							className="ai-syndication-selected-warning"
						>
							{ __(
								'New products are not auto-included. Return here to add them manually as your catalog grows.',
								'woocommerce-ai-storefront'
							) }
						</Notice>
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
						   Link target routes to the Endpoints tab, which
						   has per-endpoint URLs + a "try it" curl example
						   per row. Keeping that deep-link here rather than
						   inline in the footer avoids duplicating the
						   Endpoints tab's surface and preserves single
						   source of truth for endpoint info.
						*/ }
						<Button
							variant="link"
							href={ `${
								window.location.pathname
							}${ window.location.search.replace(
								/([?&])tab=[^&]*/,
								''
							) }${
								window.location.search.includes( '?' )
									? '&'
									: '?'
							}tab=endpoints` }
							style={ { fontSize: '13px' } }
						>
							{ __(
								'Test with an AI agent \u2192',
								'woocommerce-ai-storefront'
							) }
						</Button>
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
 * Footer-of-panel disclosure line — used by 'all' and 'categories'
 * for their benign auto-inclusion explanations. The 'selected' mode
 * uses a `<Notice status="warning">` instead because its disclosure
 * is an actual behavioral surprise, not a neutral fact.
 *
 * @param {Object} root0          Component props.
 * @param {Node}   root0.children Disclosure text.
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
