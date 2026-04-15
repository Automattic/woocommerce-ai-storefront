import { useState, useEffect } from '@wordpress/element';
import {
	Card,
	CardBody,
	CardHeader,
	Button,
	SelectControl,
	CheckboxControl,
	Spinner,
	SearchControl,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { decodeEntities } from '@wordpress/html-entities';
import apiFetch from '@wordpress/api-fetch';

const ProductSelection = ( { settings, onChange, onSave, isSaving } ) => {
	const [ categories, setCategories ] = useState( [] );
	const [ products, setProducts ] = useState( [] );
	const [ productSearch, setProductSearch ] = useState( '' );
	const [ isLoadingCategories, setIsLoadingCategories ] = useState( false );
	const [ isLoadingProducts, setIsLoadingProducts ] = useState( false );

	// Load categories on mount.
	useEffect( () => {
		setIsLoadingCategories( true );
		apiFetch( {
			path: '/wc/v3/ai-syndication/admin/search/categories',
		} )
			.then( ( result ) => setCategories( result ) )
			.catch( () => {} )
			.finally( () => setIsLoadingCategories( false ) );
	}, [] );

	// Search products.
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
		<>
			<Card>
				<CardHeader>
					<h2>
						{ __(
							'Product Selection',
							'woocommerce-ai-syndication'
						) }
					</h2>
				</CardHeader>
				<CardBody>
					<p>
						{ __(
							'Choose which products AI agents can discover and recommend.',
							'woocommerce-ai-syndication'
						) }
					</p>

					<SelectControl
						label={ __(
							'Expose products',
							'woocommerce-ai-syndication'
						) }
						value={ settings.product_selection_mode || 'all' }
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

					{ settings.product_selection_mode === 'categories' && (
						<div style={ { marginTop: '16px' } }>
							<h3>
								{ __(
									'Select Categories',
									'woocommerce-ai-syndication'
								) }
							</h3>
							{ isLoadingCategories ? (
								<Spinner />
							) : (
								<div
									style={ {
										maxHeight: '300px',
										overflow: 'auto',
										border: '1px solid #ddd',
										borderRadius: '4px',
										padding: '8px',
									} }
								>
									{ categories.map( ( cat ) => (
										<div
											key={ cat.id }
											style={ { marginBottom: '8px' } }
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
							<p style={ { color: '#757575', fontSize: '12px' } }>
								{ selectedCategories.length }{ ' ' }
								{ __(
									'categories selected',
									'woocommerce-ai-syndication'
								) }
							</p>
						</div>
					) }

					{ settings.product_selection_mode === 'selected' && (
						<div style={ { marginTop: '16px' } }>
							<h3>
								{ __(
									'Select Products',
									'woocommerce-ai-syndication'
								) }
							</h3>
							<SearchControl
								value={ productSearch }
								onChange={ setProductSearch }
								placeholder={ __(
									'Search products…',
									'woocommerce-ai-syndication'
								) }
							/>
							{ isLoadingProducts ? (
								<Spinner />
							) : (
								<div
									style={ {
										maxHeight: '300px',
										overflow: 'auto',
										border: '1px solid #ddd',
										borderRadius: '4px',
										padding: '8px',
										marginTop: '8px',
									} }
								>
									{ products.map( ( product ) => (
										<div
											key={ product.id }
											style={ { marginBottom: '8px' } }
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
							<p style={ { color: '#757575', fontSize: '12px' } }>
								{ selectedProducts.length }{ ' ' }
								{ __(
									'products selected',
									'woocommerce-ai-syndication'
								) }
							</p>
						</div>
					) }
				</CardBody>
			</Card>

			<div style={ { marginTop: '16px' } }>
				<Button
					variant="primary"
					isBusy={ isSaving }
					disabled={ isSaving }
					onClick={ onSave }
				>
					{ isSaving
						? __( 'Saving…', 'woocommerce-ai-syndication' )
						: __( 'Save Changes', 'woocommerce-ai-syndication' ) }
				</Button>
			</div>
		</>
	);
};

export default ProductSelection;
