import { createRoot } from '@wordpress/element';
import AISyndicationSettings from './settings-page';
import '../../data/ai-syndication';

const container = document.getElementById( 'wc-ai-syndication-settings' );

if ( container ) {
	createRoot( container ).render( <AISyndicationSettings /> );
}
