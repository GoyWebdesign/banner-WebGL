/**
 * Animated Section FX — moteur WebGL front.
 * Vanilla ES2017, aucun build requis. Chaque section .asfx-section avec
 * data-asfx-effect != "none" obtient son contexte WebGL.
 */
( function () {
    'use strict';

    var VERTEX = [
        'attribute vec2 aPos;',
        'varying vec2 vUv;',
        'void main() {',
        '  vUv = aPos * 0.5 + 0.5;',
        '  gl_Position = vec4( aPos, 0.0, 1.0 );',
        '}',
    ].join( '\n' );

    // uEffect : 0 = wave, 1 = ripple, 2 = displace
    var FRAGMENT = [
        'precision mediump float;',
        'varying vec2 vUv;',
        'uniform sampler2D uTex;',
        'uniform float uTime;      // secondes',
        'uniform float uIntensity; // 0..1',
        'uniform vec2 uRes;         // resolution canvas',
        'uniform vec2 uTexRes;      // resolution image',
        'uniform int uEffect;',

        'void main() {',
        '  vec2 uv = vUv;',

        // Cover : recadrer la texture comme background-size: cover
        '  float canvasAR = uRes.x / uRes.y;',
        '  float texAR = uTexRes.x / uTexRes.y;',
        '  vec2 scale = vec2(1.0);',
        '  if ( canvasAR > texAR ) {',
        '    scale.y = canvasAR / texAR;',
        '  } else {',
        '    scale.x = texAR / canvasAR;',
        '  }',
        '  uv = ( uv - 0.5 ) / scale + 0.5;',
        // Overscan leaves room for the animated warp, avoiding stretched edge pixels.
        '  uv = ( uv - 0.5 ) / ( 1.0 + uIntensity * 0.4 ) + 0.5;',

        '  float k = uIntensity * 0.08;',

        '  if ( uEffect == 0 ) {',
        // wave : cisaillement sinusoïdal
        '    uv.x += sin( uv.y * 12.0 + uTime * 2.0 ) * k;',
        '    uv.y += cos( uv.x * 6.0 + uTime * 1.3 ) * k * 0.5;',
        '  } else if ( uEffect == 1 ) {',
        // ripple : ondes concentriques depuis le centre
        '    vec2 c = uv - 0.5;',
        '    float d = length( c );',
        '    float r = sin( d * 30.0 - uTime * 3.0 ) * k * ( 1.0 - d );',
        '    uv += normalize( c + 0.0001 ) * r;',
        '  } else {',
        // displace : bruit organique multi-octaves (pseudo-Perlin par sommes de sin)
        '    float t = uTime * 0.8;',
        '    float n1 = sin( uv.x * 8.0 + t ) * sin( uv.y * 8.0 + t * 1.7 );',
        '    float n2 = sin( uv.x * 17.0 - t * 1.3 ) * sin( uv.y * 15.0 + t );',
        '    uv += vec2( n1, n2 ) * k * 0.6;',
        '  }',

        '  vec4 col = texture2D( uTex, clamp( uv, 0.0, 1.0 ) );',
        '  gl_FragColor = col;',
        '}',
    ].join( '\n' );

    function compile( gl, type, src ) {
        var s = gl.createShader( type );
        gl.shaderSource( s, src );
        gl.compileShader( s );
        if ( ! gl.getShaderParameter( s, gl.COMPILE_STATUS ) ) {
            throw new Error( gl.getShaderInfoLog( s ) );
        }
        return s;
    }

    function start( section ) {
        var canvas = section.querySelector( '.asfx-canvas' );
        var src = section.getAttribute( 'data-asfx-src' );
        var effect = section.getAttribute( 'data-asfx-effect' );
        var intensity = parseInt( section.getAttribute( 'data-asfx-intensity' ), 10 ) / 100;
        var speed = parseInt( section.getAttribute( 'data-asfx-speed' ), 10 ) / 100;
        if ( ! canvas || ! src || effect === 'none' ) {
            return;
        }

        var gl = canvas.getContext( 'webgl', { antialias: true, alpha: false } );
        if ( ! gl ) {
            canvas.style.display = 'none';
            return;
        }

        var prog = gl.createProgram();
        gl.attachShader( prog, compile( gl, gl.VERTEX_SHADER || 0, VERTEX ) );
        gl.attachShader( prog, compile( gl, gl.FRAGMENT_SHADER, FRAGMENT ) );
        gl.linkProgram( prog );
        gl.useProgram( prog );

        var buf = gl.createBuffer();
        gl.bindBuffer( gl.ARRAY_BUFFER, buf );
        gl.bufferData( gl.ARRAY_BUFFER, new Float32Array( [ -1, -1, 1, -1, -1, 1, 1, 1 ] ), gl.STATIC_DRAW );
        var loc = gl.getAttribLocation( prog, 'aPos' );
        gl.enableVertexAttribArray( loc );
        gl.vertexAttribPointer( loc, 2, gl.FLOAT, false, 0, 0 );

        var uTime = gl.getUniformLocation( prog, 'uTime' );
        var uIntensity = gl.getUniformLocation( prog, 'uIntensity' );
        var uRes = gl.getUniformLocation( prog, 'uRes' );
        var uTexRes = gl.getUniformLocation( prog, 'uTexRes' );
        var uEffect = gl.getUniformLocation( prog, 'uEffect' );

        var effectId = { wave: 0, ripple: 1, displace: 2 }[ effect ];
        if ( effectId === undefined ) {
            effectId = 2;
        }
        gl.uniform1i( uEffect, effectId );
        gl.uniform1f( uIntensity, intensity );

        var texture = gl.createTexture();
        var ready = false;
        var img = new Image();
        img.crossOrigin = 'anonymous';
        img.onload = function () {
            gl.bindTexture( gl.TEXTURE_2D, texture );
            gl.pixelStorei( gl.UNPACK_FLIP_Y_WEBGL, true );
            gl.texImage2D( gl.TEXTURE_2D, 0, gl.RGBA, gl.RGBA, gl.UNSIGNED_BYTE, img );
            gl.texParameteri( gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE );
            gl.texParameteri( gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE );
            gl.texParameteri( gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR );
            gl.uniform2f( uTexRes, img.naturalWidth, img.naturalHeight );
            ready = true;
        };
        img.src = src;

        function resize() {
            var dpr = Math.min( window.devicePixelRatio || 1, 2 );
            var w = Math.max( 1, Math.floor( section.clientWidth * dpr ) );
            var h = Math.max( 1, Math.floor( section.clientHeight * dpr ) );
            if ( canvas.width !== w || canvas.height !== h ) {
                canvas.width = w;
                canvas.height = h;
                gl.viewport( 0, 0, w, h );
                gl.uniform2f( uRes, w, h );
            }
        }

        var visible = false;
        var startT = null;

        function frame( t ) {
            requestAnimationFrame( frame );
            if ( ! ready || ! visible || document.hidden ) {
                return;
            }
            resize();
            if ( startT === null ) {
                startT = t;
            }
            gl.uniform1f( uTime, ( ( t - startT ) / 1000 ) * ( 0.2 + speed * 2.0 ) );
            gl.drawArrays( gl.TRIANGLE_STRIP, 0, 4 );
        }

        if ( window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
            // Accessibilité : pas d'animation, une frame statique (le fallback
            // <img> prend le relais via CSS si WebGL échoue aussi).
            visible = true;
            img.onload = function () {
                gl.bindTexture( gl.TEXTURE_2D, texture );
                gl.pixelStorei( gl.UNPACK_FLIP_Y_WEBGL, true );
                gl.texImage2D( gl.TEXTURE_2D, 0, gl.RGBA, gl.RGBA, gl.UNSIGNED_BYTE, img );
                gl.texParameteri( gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR );
                gl.uniform2f( uTexRes, img.naturalWidth, img.naturalHeight );
                gl.uniform2f( uRes, canvas.width, canvas.height );
                gl.drawArrays( gl.TRIANGLE_STRIP, 0, 4 );
            };
            img.src = src;
            return;
        }

        new IntersectionObserver(
            function ( entries ) {
                visible = entries[ 0 ].isIntersecting;
            },
            { rootMargin: '100px' }
        ).observe( section );

        window.addEventListener( 'resize', resize, { passive: true } );
        resize();
        requestAnimationFrame( frame );
    }

    function boot() {
        document.querySelectorAll( '.asfx-section' ).forEach( start );
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', boot );
    } else {
        boot();
    }
} )();