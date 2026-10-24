/**
 * Redraw the oversize scale as the multiplier is typed.
 *
 * The diagram is not decoration next to the field, it is a picture of what that field
 * currently does, so leaving it showing the saved value while somebody types a new one
 * would make it the least trustworthy thing on the page.
 *
 * The axis rule here has to match TIMU_Image_Support_Admin_Page::diagram_scale() and
 * ::diagram_shows_normal(), because PHP draws the first frame and this redraws every one
 * after it. If they disagree, the picture jumps the moment you save.
 */
( function () {
	'use strict';

	var input = document.getElementById( 'timu_image_support_oversize_threshold' );
	var svg = document.querySelector( '.timu-diagram[data-timu-widest]' );

	if ( ! input || ! svg ) {
		return;
	}

	var settings = window.timuImageOverview || {};
	var widest = parseFloat( svg.getAttribute( 'data-timu-widest' ) ) || 0;
	var min = parseFloat( input.getAttribute( 'min' ) ) || 1.3;
	var max = parseFloat( input.getAttribute( 'max' ) ) || 10;

	if ( widest <= 0 ) {
		return;
	}

	var el = {
		safe: svg.querySelector( '.js-timu-band-safe' ),
		flag: svg.querySelector( '.js-timu-band-flag' ),
		widestLine: svg.querySelector( '.js-timu-widest-line' ),
		widestTick: svg.querySelector( '.js-timu-widest-tick' ),
		widestCap: svg.querySelector( '.js-timu-widest-cap' ),
		normal: svg.querySelector( '.js-timu-normal' ),
		normalLine: svg.querySelector( '.js-timu-normal-line' ),
		normalCap: svg.querySelector( '.js-timu-normal-cap' ),
		flagLine: svg.querySelector( '.js-timu-flag-line' ),
		flagTick: svg.querySelector( '.js-timu-flag-tick' ),
		flagCap: svg.querySelector( '.js-timu-flag-cap' ),
		scaleCap: svg.querySelector( '.js-timu-scale-cap' ),
		title: svg.querySelector( 'title' )
	};

	function axisFor( flagAt ) {
		return Math.max( 6000, Math.ceil( ( flagAt * 1.15 ) / 1000 ) * 1000 );
	}

	function positionOn( axis, px ) {
		return Math.round( ( 60 + ( Math.min( px, axis ) / axis ) * 620 ) * 100 ) / 100;
	}

	function move( node, x ) {
		if ( node ) {
			node.setAttribute( 'x', x );
		}
	}

	/**
	 * Push the two captions apart when their marks sit close together.
	 *
	 * Mirrors TIMU_Image_Support_Admin_Page::captions_collide(). Centred on their marks, "widest served"
	 * and "flagged from here" overlap into one unreadable word below about 90 units of
	 * separation, which is where the lowest allowed multipliers put them.
	 */
	function anchorCaptions( widestX, flagX ) {
		var tight = ( flagX - widestX ) < 90;

		if ( el.widestCap ) {
			el.widestCap.setAttribute( 'text-anchor', tight ? 'end' : 'middle' );
			el.widestCap.setAttribute( 'x', tight ? widestX - 4 : widestX );
		}

		if ( el.flagCap ) {
			el.flagCap.setAttribute( 'text-anchor', tight ? 'start' : 'middle' );
			el.flagCap.setAttribute( 'x', tight ? flagX + 4 : flagX );
		}
	}

	function span( node, x ) {
		if ( node ) {
			node.setAttribute( 'x1', x );
			node.setAttribute( 'x2', x );
		}
	}

	function px( value ) {
		return String( value ) + 'px';
	}

	function redraw() {
		var multiplier = parseFloat( input.value );

		// An empty or half-typed field is somebody mid-keystroke, not a value. Leave the
		// last good frame on screen rather than flashing something meaningless at them.
		if ( isNaN( multiplier ) ) {
			return;
		}

		multiplier = Math.min( Math.max( multiplier, min ), max );

		var flagAt = Math.round( widest * multiplier );
		var axis = axisFor( flagAt );
		var flagX = positionOn( axis, flagAt );
		var widestX = positionOn( axis, widest );

		if ( el.safe ) {
			el.safe.setAttribute( 'width', Math.max( 0, flagX - 60 ) );
		}

		if ( el.flag ) {
			el.flag.setAttribute( 'x', flagX );
			el.flag.setAttribute( 'width', Math.max( 0, 680 - flagX ) );
		}

		span( el.widestLine, widestX );
		move( el.widestTick, widestX );

		span( el.flagLine, flagX );
		move( el.flagTick, flagX );

		anchorCaptions( widestX, flagX );

		if ( el.flagTick ) {
			el.flagTick.textContent = px( flagAt );
		}

		if ( el.scaleCap ) {
			el.scaleCap.textContent = px( axis );
		}

		// Once the axis stretches, 2560px and the widest served size collide. Drop the
		// marker rather than printing two captions on top of each other.
		if ( el.normal ) {
			if ( axis <= 9000 ) {
				el.normal.removeAttribute( 'hidden' );
				var normalX = positionOn( axis, 2560 );
				span( el.normalLine, normalX );
				move( el.normalCap, normalX );
			} else {
				el.normal.setAttribute( 'hidden', 'hidden' );
			}
		}

		// The description sentence above the diagram names the same number, so it moves
		// with it. One of them being stale is worse than neither of them updating.
		var stated = document.querySelector( '.timu-flag-px' );

		if ( stated ) {
			stated.textContent = px( flagAt );
		}

		if ( settings.labelTemplate ) {
			var label = settings.labelTemplate
				.replace( '{widest}', widest )
				.replace( '{flag}', flagAt );

			svg.setAttribute( 'aria-label', label );

			if ( el.title ) {
				el.title.textContent = label;
			}
		}
	}

	input.addEventListener( 'input', redraw );
	input.addEventListener( 'change', redraw );

	/**
	 * The file-size diagram, on the same terms.
	 *
	 * Mirrors TIMU_Image_Support_Admin_Page::heavy_axis() and ::format_mb(). The bars are fixed samples;
	 * only the threshold marker and which bars count as large move.
	 */
	var heavyInput = document.getElementById( 'timu_image_support_heavy_mb' );
	var heavySvg = document.querySelector( '.timu-diagram[data-timu-heavy-axis]' );

	if ( ! heavyInput || ! heavySvg ) {
		return;
	}

	var heavyMin = parseFloat( heavyInput.getAttribute( 'min' ) ) || 0.1;
	var heavyMax = parseFloat( heavyInput.getAttribute( 'max' ) ) || 100;

	var heavyEl = {
		bars: heavySvg.querySelectorAll( '.js-timu-heavy-bar' ),
		sizes: heavySvg.querySelectorAll( '.js-timu-heavy-size' ),
		line: heavySvg.querySelector( '.js-timu-heavy-line' ),
		tick: heavySvg.querySelector( '.js-timu-heavy-tick' ),
		cap: heavySvg.querySelector( '.js-timu-heavy-cap' ),
		title: heavySvg.querySelector( 'title' )
	};

	function heavyAxisFor( mb ) {
		return Math.max( 3, Math.min( Math.ceil( mb * 2 ), 12 ) );
	}

	function heavyPosition( axis, mb ) {
		return Math.round( ( 150 + ( Math.min( mb, axis ) / axis ) * 460 ) * 100 ) / 100;
	}

	// Matches what size_format() produces on the PHP side: whole kilobytes below a
	// megabyte, always one decimal above. The decimal matters. Without toFixed, typing "1"
	// showed "1 MB" and saving turned it into "1.0 MB", so the label appeared to change on
	// its own the moment the page reloaded.
	function asSize( mb ) {
		var units = settings.sizeTemplates || {};
		var kb = units.kb || '{n} KB';
		var mbTemplate = units.mb || '{n} MB';

		if ( mb < 1 ) {
			return kb.replace( '{n}', String( Math.round( mb * 1024 ) ) );
		}

		var rounded = Math.round( mb * 10 ) / 10;
		var text = rounded.toFixed( 1 );

		// The same decimal mark WordPress uses for this language, so the label matches the one
		// PHP draws after a save. Falls back to a plain decimal point if the language tag is
		// not one the browser recognises.
		try {
			text = rounded.toLocaleString( document.documentElement.lang || undefined, {
				minimumFractionDigits: 1,
				maximumFractionDigits: 1,
			} );
		} catch ( e ) {
			text = rounded.toFixed( 1 );
		}

		return mbTemplate.replace( '{n}', text );
	}

	function redrawHeavy() {
		var value = parseFloat( heavyInput.value );

		if ( isNaN( value ) ) {
			return;
		}

		value = Math.min( Math.max( value, heavyMin ), heavyMax );

		var axis = heavyAxisFor( value );
		var markX = heavyPosition( axis, value );
		var i;

		for ( i = 0; i < heavyEl.bars.length; i++ ) {
			var bar = heavyEl.bars[ i ];
			var mb = parseFloat( bar.getAttribute( 'data-timu-mb' ) );
			var over = mb >= value;

			bar.setAttribute( 'width', Math.max( 0, heavyPosition( axis, mb ) - 150 ) );
			bar.setAttribute( 'fill', over ? '#dbe3ec' : '#e8eaed' );
			bar.setAttribute( 'stroke', over ? '#123456' : '#c3c4c7' );
		}

		for ( i = 0; i < heavyEl.sizes.length; i++ ) {
			var size = heavyEl.sizes[ i ];
			var sizeMb = parseFloat( size.getAttribute( 'data-timu-mb' ) );

			size.setAttribute( 'x', heavyPosition( axis, sizeMb ) + 8 );
		}

		span( heavyEl.line, markX );
		move( heavyEl.tick, markX );
		move( heavyEl.cap, markX );

		if ( heavyEl.tick ) {
			heavyEl.tick.textContent = asSize( value );
		}

		if ( settings.heavyLabelTemplate ) {
			var heavyLabel = settings.heavyLabelTemplate.replace( '{heavy}', asSize( value ) );

			heavySvg.setAttribute( 'aria-label', heavyLabel );

			if ( heavyEl.title ) {
				heavyEl.title.textContent = heavyLabel;
			}
		}
	}

	heavyInput.addEventListener( 'input', redrawHeavy );
	heavyInput.addEventListener( 'change', redrawHeavy );
}() );
