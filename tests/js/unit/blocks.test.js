/**
 * blocks/[name]/index.js — editor registrations for the plugin's dynamic blocks.
 *
 * Each file is plain ES5 against window.wp.*: it registers the block with an
 * edit() that previews the PHP render through ServerSideRender and a null
 * save(). The wp globals are recording fakes.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { loadScript } from '../helpers/load.js';

let registered;
let wp;

beforeEach( () => {
	registered = {};
	const ServerSideRender = function ServerSideRender() {};
	wp = {
		blocks: {
			registerBlockType: vi.fn(
				( name, settings ) => ( registered[ name ] = settings )
			),
		},
		element: {
			createElement: vi.fn( ( type, props, ...children ) => ( {
				type,
				props,
				children,
			} ) ),
		},
		blockEditor: {
			useBlockProps: vi.fn( () => ( { className: 'wp-block-x' } ) ),
		},
		serverSideRender: ServerSideRender,
	};
	vi.stubGlobal( 'wp', wp );
} );

describe( 'kwv/brand-awards', () => {
	it( 'registers a server-rendered block with a null save', async () => {
		await loadScript( 'blocks/brand-awards/index.js' );
		const settings = registered[ 'kwv/brand-awards' ];

		const tree = settings.edit( { attributes: { a: 1 } } );

		expect( settings.save() ).toBeNull();
		expect( tree.type ).toBe( 'div' );
		expect( tree.props ).toEqual( { className: 'wp-block-x' } );
		expect( tree.children[ 0 ].type ).toBe( wp.serverSideRender );
		expect( tree.children[ 0 ].props ).toEqual( {
			block: 'kwv/brand-awards',
			attributes: { a: 1 },
		} );
	} );
} );

describe( 'kwv/event-accommodation', () => {
	it( 'previews the event in context, passing its post ID to the server render', async () => {
		await loadScript( 'blocks/event-accommodation/index.js' );
		const settings = registered[ 'kwv/event-accommodation' ];

		const tree = settings.edit( {
			attributes: {},
			context: { postId: 42 },
		} );

		expect( settings.save() ).toBeNull();
		expect( tree.children[ 0 ].props ).toEqual( {
			block: 'kwv/event-accommodation',
			attributes: {},
			urlQueryArgs: { post_id: 42 },
		} );
	} );

	it( 'sends no query args outside a post context (e.g. the Site Editor)', async () => {
		await loadScript( 'blocks/event-accommodation/index.js' );
		const { edit } = registered[ 'kwv/event-accommodation' ];

		expect(
			edit( { attributes: {} } ).children[ 0 ].props.urlQueryArgs
		).toEqual( {} );
		expect(
			edit( { attributes: {}, context: {} } ).children[ 0 ].props
				.urlQueryArgs
		).toEqual( {} );
	} );
} );
