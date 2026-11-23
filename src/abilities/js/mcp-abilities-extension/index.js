/**
 * MCP Manager Abilities tab — "Action" column extension.
 *
 * Consumes the sibling `acrossai-mcp-manager` plugin's public JS filter
 * `acrossaiMcpManager.abilities.fields` (documented at
 * `acrossai-mcp-manager/docs/abilities-tab-js-filters.md`) and appends a
 * right-most Action column with an Edit deep-link into this plugin's
 * Custom Abilities edit view (URL scheme owned by Feature 043).
 *
 * @since 0.0.6
 */
import { addFilter } from '@wordpress/hooks';
import { createElement } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

const baseEditUrl =
	window.acrossaiAbilitiesManagerMcpExtension?.editBaseUrl ||
	'admin.php?page=acrossai-abilities-manager';

addFilter(
	'acrossaiMcpManager.abilities.fields',
	'acrossai/action-edit',
	( fields ) => [
		...fields,
		{
			id: 'aam_action',
			label: __( 'Action', 'acrossai-abilities-manager' ),
			enableSorting: false,
			enableHiding: false,
			render: ( { item } ) =>
				createElement(
					Button,
					{
						variant: 'secondary',
						size: 'small',
						href: addQueryArgs( baseEditUrl, {
							action: 'edit',
							slug: item.slug,
						} ),
						target: '_blank',
						rel: 'noopener noreferrer',
					},
					__( 'Edit', 'acrossai-abilities-manager' ),
				),
		},
	],
);
