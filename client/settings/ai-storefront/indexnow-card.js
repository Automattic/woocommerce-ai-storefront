/**
 * Discovery-tab card: Instant indexing (IndexNow).
 *
 * Lets the merchant turn IndexNow on/off, see and regenerate the ownership
 * key, and read the last submission result. When enabled, the plugin submits
 * public URLs to IndexNow (Bing, Yandex, Seznam, Naver, Yep) from the
 * server-side flush cron, not from this card; Google does not consume IndexNow.
 */

import { useState } from '@wordpress/element';
import {
	Card,
	CardBody,
	CheckboxControl,
	Button,
	Notice,
	ExternalLink,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';
import { colors } from './tokens';

/**
 * Compact relative age, e.g. "just now", "2m ago", "2h ago", "3d ago".
 *
 * @param {number} seconds Age in seconds.
 * @return {string} Relative phrase.
 */
export function formatRelativeAge( seconds ) {
	if ( seconds < 60 ) {
		return __( 'just now', 'woocommerce-ai-storefront' );
	}
	if ( seconds < 3600 ) {
		return sprintf(
			/* translators: %d: number of minutes. */
			__( '%dm ago', 'woocommerce-ai-storefront' ),
			Math.floor( seconds / 60 )
		);
	}
	if ( seconds < 86400 ) {
		return sprintf(
			/* translators: %d: number of hours. */
			__( '%dh ago', 'woocommerce-ai-storefront' ),
			Math.floor( seconds / 3600 )
		);
	}
	return sprintf(
		/* translators: %d: number of days. */
		__( '%dd ago', 'woocommerce-ai-storefront' ),
		Math.floor( seconds / 86400 )
	);
}

/**
 * Human status line from the stored last_result.
 *
 * @param {Object} lastResult { time, count, code, ok } or {}.
 * @param {number} nowSeconds Current unix time in seconds.
 * @return {string} Status text.
 */
export function formatIndexNowStatus( lastResult, nowSeconds ) {
	if ( ! lastResult || ! lastResult.time ) {
		return __( 'No submissions yet.', 'woocommerce-ai-storefront' );
	}
	const ago = formatRelativeAge( nowSeconds - lastResult.time );
	if ( lastResult.ok ) {
		return sprintf(
			/* translators: 1: URL count, 2: HTTP code, 3: relative time. */
			__(
				'Last submitted: %1$d URL(s) · HTTP %2$d · %3$s',
				'woocommerce-ai-storefront'
			),
			lastResult.count,
			lastResult.code,
			ago
		);
	}
	if ( 0 === lastResult.code ) {
		return sprintf(
			/* translators: %s: relative time. */
			__(
				'Last attempt failed: connection error · %s',
				'woocommerce-ai-storefront'
			),
			ago
		);
	}
	return sprintf(
		/* translators: 1: HTTP code, 2: relative time. */
		__(
			'Last attempt failed: HTTP %1$d · %2$s',
			'woocommerce-ai-storefront'
		),
		lastResult.code,
		ago
	);
}

/**
 * The IndexNow settings card.
 *
 * @param {Object}   props
 * @param {Object}   props.settings   Plugin settings.
 * @param {Function} props.onChange   Settings updater.
 * @param {string}   props.keyFileUrl Public URL of the `{key}.txt` ownership
 *                                    file ('' until a key exists AND endpoints
 *                                    load; the link is also gated on the key).
 * @return {JSX.Element} Card.
 */
export function IndexNowCard( { settings, onChange, keyFileUrl } ) {
	const enabled = settings.indexnow_enabled === 'yes';
	const key = settings.indexnow_key || '';
	const [ regenerating, setRegenerating ] = useState( false );
	const [ error, setError ] = useState( '' );

	const regenerate = async () => {
		if (
			// eslint-disable-next-line no-alert
			! window.confirm(
				__(
					'Regenerate the IndexNow key? Pending verifications for the old key will be invalidated.',
					'woocommerce-ai-storefront'
				)
			)
		) {
			return;
		}
		setRegenerating( true );
		setError( '' );
		try {
			const res = await apiFetch( {
				path: '/wc/v3/ai-storefront/admin/regenerate-indexnow-key',
				method: 'POST',
			} );
			onChange( { indexnow_key: res.indexnow_key } );
		} catch ( _e ) {
			setError(
				__(
					'Could not regenerate the key. Please try again.',
					'woocommerce-ai-storefront'
				)
			);
		} finally {
			setRegenerating( false );
		}
	};

	const muted = {
		color: colors.textMuted,
		fontSize: '12px',
		marginTop: '12px',
		marginBottom: '8px',
	};

	return (
		<Card style={ { marginTop: '32px' } }>
			<CardBody>
				<h3 style={ { margin: '0 0 8px', fontSize: '14px' } }>
					{ __(
						'Instant indexing (IndexNow)',
						'woocommerce-ai-storefront'
					) }
				</h3>
				<p style={ muted }>
					{ __(
						'Tell IndexNow-supported engines (Bing, Yandex, Seznam, Naver, Yep) the moment your catalog changes, so they re-crawl in seconds instead of days, keeping your products current in AI-powered search results. Only public URLs are sent; Google does not use IndexNow.',
						'woocommerce-ai-storefront'
					) }
				</p>
				<CheckboxControl
					label={ __(
						'Notify search engines instantly (IndexNow)',
						'woocommerce-ai-storefront'
					) }
					checked={ enabled }
					onChange={ ( checked ) =>
						onChange( { indexnow_enabled: checked ? 'yes' : 'no' } )
					}
					__nextHasNoMarginBottom
				/>
				{ enabled && (
					<div style={ { marginTop: '16px' } }>
						<p
							style={ {
								fontSize: '12px',
								color: colors.textMuted,
								marginBottom: '4px',
							} }
						>
							{ __(
								'Verification key',
								'woocommerce-ai-storefront'
							) }
						</p>
						<code style={ { wordBreak: 'break-all' } }>
							{ key ||
								__(
									'(not generated yet)',
									'woocommerce-ai-storefront'
								) }
						</code>
						{ key && keyFileUrl && (
							<div style={ { marginTop: '4px' } }>
								<ExternalLink href={ keyFileUrl }>
									{ __(
										'View key file',
										'woocommerce-ai-storefront'
									) }
								</ExternalLink>
							</div>
						) }
						<div style={ { marginTop: '8px' } }>
							<Button
								variant="secondary"
								onClick={ regenerate }
								isBusy={ regenerating }
								disabled={ regenerating }
							>
								{ key
									? __(
											'Regenerate key',
											'woocommerce-ai-storefront'
									  )
									: __(
											'Generate key',
											'woocommerce-ai-storefront'
									  ) }
							</Button>
						</div>
						{ error && (
							<Notice
								status="error"
								isDismissible={ false }
								className="wc-ai-storefront-indexnow-error"
							>
								{ error }
							</Notice>
						) }
						<p style={ muted }>
							{ formatIndexNowStatus(
								settings.indexnow_last_result,
								Math.floor( Date.now() / 1000 )
							) }
						</p>
					</div>
				) }
			</CardBody>
		</Card>
	);
}

export default IndexNowCard;
