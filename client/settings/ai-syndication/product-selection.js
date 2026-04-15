import { useState, useEffect } from '@wordpress/element';
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

const MODE_DESCRIPTIONS = {
	all: __(
		'Every published product in your store is discoverable by AI agents. Best for stores that want maximum exposure.',
		'woocommerce-ai-syndication'
	),
	categories: __(
		'Only products in the categories you select below will be visible. Use this to focus AI discovery on specific product lines.',
		'woocommerce-ai-syndication'
	),
	selected: __(
		'Only the specific products you choose below will be visible. Use this for curated AI-only collections or high-margin items.',
		'woocommerce-ai-syndication'
	),
};

const CountPill = ( { count, label } ) => {
	const hasItems = count > 0;
	return (
		<span
			style={ {
				display: 'inline-block',
				background: hasItems ? '#edfaef' : '#f0f0f0',
				color: hasItems ? '#00a32a' : '#757575',
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

const ProductSelection = ( { settings, onChange, onSave, isSaving } ) => {
	const [ categories, setCategories ] = useState( [] );
	const [ products, setProducts ] = useState( [] );
	const [ productSearch, setProductSearch ] = useState( '' );
	const [ isLoadingCategories, setIsLoadingCategories ] = useState( false );
	const [ isLoadingProducts, setIsLoadingProducts ] = useState( false );

	useEffect( () => {
		setIsLoadingCategories( true );
		apiFetch( {
			path: '/wc/v3/ai-syndication/admin/search/categories',
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
			path: `/wc/v3/ai-syndication/admin/search/products?search=${ encodeURIComponent(
				productSearch
			) }`,
		} )
			.then( ( result ) => setProducts( result ) )
			.catch( () => {} )
			.finally( () => setIsLoadingProducts( false ) );
	}, [ productSearch, settings.product_selection_mode ] );

	const selectedCategories = settings.selected_categories || [];
	const selectedProducts = settings.selected_products || [];
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

	return (
		<div>
			<Card>
				<CardBody>
					<h3 style={ { margin: '0 0 8px', fontSize: '14px' } }>
						{ __(
							'Product Selection',
							'woocommerce-ai-syndication'
						) }
					</h3>
					<p
						style={ {
							color: '#50575e',
							fontSize: '13px',
							margin: '0 0 16px',
						} }
					>
						{ __(
							'Choose which products AI agents can discover and recommend to shoppers.',
							'woocommerce-ai-syndication'
						) }
					</p>

					<SelectControl
						label={ __(
							'Products available to AI agents',
							'woocommerce-ai-syndication'
						) }
						value={ mode }
						options={ [
							{
								label: __(
									'All published products',
									'woocommerce-ai-syndication'
								),
								value: 'all',
							},
							{
								label: __(
									'Products in selected categories',
									'woocommerce-ai-syndication'
								),
								value: 'categories',
							},
							{
								label: __(
									'Specific products only',
									'woocommerce-ai-syndication'
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
								color: '#757575',
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
										color: '#1d2327',
									} }
								>
									{ __(
										'Categories',
										'woocommerce-ai-syndication'
									) }
								</span>
								<CountPill
									count={ selectedCategories.length }
									label={
										/* translators: %d: number of selected items */
										__(
											'%d selected',
											'woocommerce-ai-syndication'
										)
									}
								/>
							</div>
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
										background: '#f6f7f7',
										borderRadius: '4px',
										padding: '4px 16px',
									} }
								>
									{ categories.map( ( cat, index ) => (
										<div
											key={ cat.id }
											style={ {
												padding: '6px 0',
												borderBottom:
													index <
													categories.length - 1
														? '1px solid #e0e0e0'
														: 'none',
											} }
										>
											<CheckboxControl
												label={ sprintf(
													/* translators: %1$s: category name, %2$d: product count */
													'%1$s (%2$d)',
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
										color: '#1d2327',
									} }
								>
									{ __(
										'Products',
										'woocommerce-ai-syndication'
									) }
								</span>
								<CountPill
									count={ selectedProducts.length }
									label={
										/* translators: %d: number of selected items */
										__(
											'%d selected',
											'woocommerce-ai-syndication'
										)
									}
								/>
							</div>
							<SearchControl
								value={ productSearch }
								onChange={ setProductSearch }
								placeholder={ __(
									'Search products…',
									'woocommerce-ai-syndication'
								) }
							/>
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
										background: '#f6f7f7',
										borderRadius: '4px',
										padding: '4px 16px',
										marginTop: '8px',
									} }
								>
									{ products.length === 0 && (
										<p
											style={ {
												color: '#757575',
												fontSize: '13px',
												textAlign: 'center',
												padding: '16px 0',
												margin: 0,
											} }
										>
											{ productSearch
												? __(
														'No products found. Try a different search.',
														'woocommerce-ai-syndication'
												  )
												: __(
														'Start typing to search your products.',
														'woocommerce-ai-syndication'
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
														? '1px solid #e0e0e0'
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

					{ /* Save button inside card */ }
					<div
						style={ {
							marginTop: '16px',
							paddingTop: '16px',
							borderTop: '1px solid #f0f0f0',
						} }
					>
						<Button
							variant="primary"
							isBusy={ isSaving }
							disabled={ isSaving }
							onClick={ onSave }
						>
							{ isSaving
								? __( 'Saving…', 'woocommerce-ai-syndication' )
								: __(
										'Save Changes',
										'woocommerce-ai-syndication'
								  ) }
						</Button>
					</div>
				</CardBody>
			</Card>
		</div>
	);
};

export default ProductSelection;
