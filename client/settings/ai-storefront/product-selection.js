import { useState, useEffect, useMemo } from '@wordpress/element';
import {
	Card,
	CardBody,
	Button,
	SelectControl,
	CheckboxControl,
	Spinner,
	SearchControl,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { decodeEntities } from '@wordpress/html-entities';
import apiFetch from '@wordpress/api-fetch';
import { colors } from './tokens';

const MODE_DESCRIPTIONS = {
	all: __(
		'Every published product in your store is discoverable by AI crawlers. Best for stores that want maximum exposure.',
		'woocommerce-ai-storefront'
	),
	categories: __(
		'Only products in the categories you select below will be included in discovery endpoints. Use this to focus AI visibility on specific product lines.',
		'woocommerce-ai-storefront'
	),
	selected: __(
		'Only the specific products you choose below will appear in discovery endpoints. Use this for curated collections or high-margin items.',
		'woocommerce-ai-storefront'
	),
};

const CountPill = ( { count, label } ) => {
	const hasItems = count > 0;
	return (
		<span
			style={ {
				display: 'inline-block',
				background: hasItems ? colors.successBg : colors.surfaceMuted,
				color: hasItems ? colors.success : colors.textMuted,
				fontWeight: hasItems ? '600' : '400',
				fontSize: '12px',
				borderRadius: '10px',
				padding: '2px 10px',
			} }
		>
			{ sprintf(
				/* translators: %d: number of items */
				label,
				count
			) }
		</span>
	);
};

/**
 * Token list showing selected items with remove buttons.
 * Gives merchants a clear view of their current selection
 * without scrolling through the full list.
 * @param {Object}   root0          Component props.
 * @param {Array}    root0.items    Selected items with id and label.
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
				padding: '12px',
				background: colors.surface,
				border: `1px solid ${ colors.borderSubtle }`,
				borderRadius: '4px',
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
						padding: '3px 6px 3px 8px',
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

const ProductSelection = ( { settings, onChange, onSave, isSaving } ) => {
	const [ categories, setCategories ] = useState( [] );
	const [ products, setProducts ] = useState( [] );
	const [ productSearch, setProductSearch ] = useState( '' );
	const [ categorySearch, setCategorySearch ] = useState( '' );
	const [ isLoadingCategories, setIsLoadingCategories ] = useState( false );
	const [ isLoadingProducts, setIsLoadingProducts ] = useState( false );

	useEffect( () => {
		setIsLoadingCategories( true );
		apiFetch( {
			path: '/wc/v3/ai-storefront/admin/search/categories',
		} )
			.then( ( result ) => setCategories( result ) )
			.catch( () => {} )
			.finally( () => setIsLoadingCategories( false ) );
	}, [] );

	useEffect( () => {
		if ( settings.product_selection_mode !== 'selected' ) {
			return;
		}
		setIsLoadingProducts( true );
		apiFetch( {
			path: `/wc/v3/ai-storefront/admin/search/products?search=${ encodeURIComponent(
				productSearch
			) }`,
		} )
			.then( ( result ) => setProducts( result ) )
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
	const mode = settings.product_selection_mode || 'all';

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

	// Filter categories by search term.
	const filteredCategories = useMemo( () => {
		if ( ! categorySearch.trim() ) {
			return categories;
		}
		const term = categorySearch.toLowerCase();
		return categories.filter( ( cat ) =>
			decodeEntities( cat.name ).toLowerCase().includes( term )
		);
	}, [ categories, categorySearch ] );

	// Build token data for selected categories.
	const selectedCategoryTokens = useMemo( () => {
		return categories.filter( ( cat ) =>
			selectedCategories.includes( cat.id )
		);
	}, [ categories, selectedCategories ] );

	// Build token data for selected products.
	// We need to track selected products that may not be in the current search results.
	const [ selectedProductCache, setSelectedProductCache ] = useState( {} );
	useEffect( () => {
		const newCache = { ...selectedProductCache };
		products.forEach( ( p ) => {
			newCache[ p.id ] = p;
		} );
		setSelectedProductCache( newCache );
	}, [ products ] ); // eslint-disable-line react-hooks/exhaustive-deps -- Only update cache when products change.

	const selectedProductTokens = useMemo( () => {
		return selectedProducts
			.map( ( id ) => selectedProductCache[ id ] )
			.filter( Boolean );
	}, [ selectedProducts, selectedProductCache ] );

	const allCategoriesSelected =
		categories.length > 0 &&
		categories.every( ( cat ) => selectedCategories.includes( cat.id ) );
	const noCategoriesSelected = selectedCategories.length === 0;

	return (
		<div>
			<Card>
				<CardBody>
					<h3 style={ { margin: '0 0 8px', fontSize: '14px' } }>
						{ __(
							'AI Product Visibility',
							'woocommerce-ai-storefront'
						) }
					</h3>
					<p
						style={ {
							color: colors.textSecondary,
							fontSize: '13px',
							margin: '0 0 16px',
						} }
					>
						{ __(
							'Choose which products appear in your discovery endpoints (llms.txt, UCP manifest, JSON-LD, and Store API responses). This controls what AI crawlers can see and recommend.',
							'woocommerce-ai-storefront'
						) }
					</p>

					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __(
							'Products available to AI crawlers',
							'woocommerce-ai-storefront'
						) }
						value={ mode }
						options={ [
							{
								label: __(
									'All published products',
									'woocommerce-ai-storefront'
								),
								value: 'all',
							},
							{
								label: __(
									'Products in selected categories',
									'woocommerce-ai-storefront'
								),
								value: 'categories',
							},
							{
								label: __(
									'Specific products only',
									'woocommerce-ai-storefront'
								),
								value: 'selected',
							},
						] }
						onChange={ ( value ) =>
							onChange( { product_selection_mode: value } )
						}
					/>
					{ MODE_DESCRIPTIONS[ mode ] && (
						<p
							style={ {
								color: colors.textMuted,
								fontSize: '12px',
								marginTop: '4px',
								marginBottom: 0,
							} }
						>
							{ MODE_DESCRIPTIONS[ mode ] }
						</p>
					) }

					{ mode === 'categories' && (
						<div style={ { marginTop: '24px' } }>
							<div
								style={ {
									display: 'flex',
									justifyContent: 'space-between',
									alignItems: 'center',
									marginBottom: '12px',
								} }
							>
								<span
									style={ {
										fontSize: '13px',
										fontWeight: '500',
										color: colors.textPrimary,
									} }
								>
									{ __(
										'Categories',
										'woocommerce-ai-storefront'
									) }
								</span>
								<CountPill
									count={ selectedCategories.length }
									label={
										/* translators: %d: number of selected items */
										__(
											'%d selected',
											'woocommerce-ai-storefront'
										)
									}
								/>
							</div>

							{ /* Selected category tokens */ }
							<SelectedTokens
								items={ selectedCategoryTokens }
								onRemove={ toggleCategory }
							/>

							{ /* Search + bulk actions for categories */ }
							{ ! isLoadingCategories &&
								categories.length > 8 && (
									<SearchControl
										value={ categorySearch }
										onChange={ setCategorySearch }
										placeholder={ __(
											'Filter categories\u2026',
											'woocommerce-ai-storefront'
										) }
									/>
								) }
							{ ! isLoadingCategories &&
								categories.length > 0 && (
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
													selected_categories:
														categories.map(
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
										maxHeight: '300px',
										overflow: 'auto',
										background: colors.surfaceSubtle,
										borderRadius: '4px',
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
									{ filteredCategories.map(
										( cat, index ) => (
											<div
												key={ cat.id }
												style={ {
													padding: '6px 0',
													borderBottom:
														index <
														filteredCategories.length -
															1
															? `1px solid ${ colors.borderSubtle }`
															: 'none',
												} }
											>
												<CheckboxControl
													label={ sprintf(
														/* translators: %1$s: category name, %2$d: product count */
														'%1$s (%2$d)',
														decodeEntities(
															cat.name
														),
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
										)
									) }
								</div>
							) }
						</div>
					) }

					{ mode === 'selected' && (
						<div style={ { marginTop: '24px' } }>
							<div
								style={ {
									display: 'flex',
									justifyContent: 'space-between',
									alignItems: 'center',
									marginBottom: '12px',
								} }
							>
								<span
									style={ {
										fontSize: '13px',
										fontWeight: '500',
										color: colors.textPrimary,
									} }
								>
									{ __(
										'Products',
										'woocommerce-ai-storefront'
									) }
								</span>
								<CountPill
									count={ selectedProducts.length }
									label={
										/* translators: %d: number of selected items */
										__(
											'%d selected',
											'woocommerce-ai-storefront'
										)
									}
								/>
							</div>

							{ /* Selected product tokens */ }
							<SelectedTokens
								items={ selectedProductTokens }
								onRemove={ toggleProduct }
							/>

							<SearchControl
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
										margin: '8px 0 0',
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
										maxHeight: '300px',
										overflow: 'auto',
										background: colors.surfaceSubtle,
										borderRadius: '4px',
										padding: '4px 16px',
										marginTop: '8px',
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
													'%1$s - %2$s',
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
						</div>
					) }
				</CardBody>
			</Card>

			{ /*
				Page-level Save footer. Matches the Discovery tab
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
						: __( 'Save Changes', 'woocommerce-ai-storefront' ) }
				</Button>
			</div>
		</div>
	);
};

export default ProductSelection;
