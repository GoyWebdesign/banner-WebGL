<?php
/**
 * Plugin Name: Animated Section FX
 * Description: Bloc Gutenberg conteneur (flex) avec image d'arrière-plan animée en WebGL (wave, ripple, displace). Sans build : aucun npm/node requis. Compatible WordPress 6.5+, testé 7.1 / 7.2-beta.
 * Version: 0.2.2
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: animated-section-fx
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'ASFX_VERSION', '0.2.2' );
define( 'ASFX_URL', plugin_dir_url( __FILE__ ) );

/**
 * Enregistre le bloc côté serveur et les assets front.
 */
function asfx_init() {
    register_block_type(
        'asfx/animated-section',
        array(
            'api_version'     => 3,
            'render_callback' => 'asfx_render',
            'attributes'      => array(
                'backgroundId'   => array( 'type' => 'number' ),
                'backgroundUrl' => array( 'type' => 'string', 'default' => '' ),
                'effect'         => array( 'type' => 'string', 'default' => 'wave' ),
                'intensity'      => array( 'type' => 'number', 'default' => 50 ),
                'speed'          => array( 'type' => 'number', 'default' => 50 ),
                'minHeight'      => array( 'type' => 'number', 'default' => 400 ),
                'width'          => array( 'type' => 'string', 'default' => '100%' ),
                'aspectRatio'    => array( 'type' => 'string', 'default' => '' ),
                'marginTop'      => array( 'type' => 'string', 'default' => '' ),
                'marginRight'    => array( 'type' => 'string', 'default' => '' ),
                'marginBottom'   => array( 'type' => 'string', 'default' => '' ),
                'marginLeft'     => array( 'type' => 'string', 'default' => '' ),
                'paddingTop'     => array( 'type' => 'string', 'default' => '' ),
                'paddingRight'   => array( 'type' => 'string', 'default' => '' ),
                'paddingBottom'  => array( 'type' => 'string', 'default' => '' ),
                'paddingLeft'    => array( 'type' => 'string', 'default' => '' ),
                'dim'            => array( 'type' => 'number', 'default' => 35 ),
            ),
            'supports'        => array(
                'layout'     => array(
                    'allowSwitching' => true,
                    'allowEditing'   => true,
                    'allowJustification' => true,
                    'allowVerticalAlignment' => true,
                    'allowSizingOnChildren' => true,
                    'default'        => array(
                        'type' => 'flex',
                    ),
                ),
                'align'      => array( 'wide', 'full' ),
                'anchor'     => true,
                'color'      => array(
                    'text'       => true,
                    'background' => true,
                ),
                'spacing'    => array(
                    'padding'   => true,
                    'margin'    => true,
                    'blockGap'  => true,
                ),
                'typography' => array(
                    'fontSize'  => true,
                    'lineHeight' => true,
                ),
                'html'       => false,
                'innerBlocks' => true,
            ),
        )
    );

    // Front : WebGL vanilla.
    wp_register_script(
        'asfx-front',
        ASFX_URL . 'front.js',
        array(),
        ASFX_VERSION,
        true
    );

    // Styles front (canvas en fond + overlay + fallback a11y).
    wp_register_style( 'asfx-front-style', false, array(), ASFX_VERSION );
    wp_add_inline_style(
        'asfx-front-style',
        '.asfx-section{position:relative;box-sizing:border-box;overflow:hidden;isolation:isolate;}
        .asfx-canvas,.asfx-overlay,.asfx-fallback{position:absolute;inset:0;width:100%;height:100%;z-index:0;pointer-events:none;}
        .asfx-section>:not(.asfx-canvas):not(.asfx-overlay):not(.asfx-fallback){position:relative;z-index:1}
        .asfx-section.asfx-has-ratio{min-height:0}
        .asfx-overlay{background:#000;opacity:var(--asfx-dim,0.35)}
        @media (prefers-reduced-motion: reduce){.asfx-canvas{display:none}
        .asfx-section .asfx-fallback{display:block !important}}'
    );
}
add_action( 'init', 'asfx_init' );

/**
 * Charge le script d'éditeur uniquement dans l'éditeur de blocs.
 */
function asfx_editor_assets() {
    wp_enqueue_script(
        'asfx-editor',
        ASFX_URL . 'editor.js',
        array(
            'wp-blocks',
            'wp-element',
            'wp-block-editor',
            'wp-components',
            'wp-i18n',
        ),
        ASFX_VERSION,
        true
    );
}
add_action( 'enqueue_block_editor_assets', 'asfx_editor_assets' );

/**
 * Rendu serveur du bloc.
 *
 * @param array  $attributes Attributs sauvegardés.
 * @param string $content    HTML des blocs internes (InnerBlocks).
 * @return string
 */
function asfx_sanitize_width( $value ) {
    if ( ! is_string( $value ) || ! preg_match( '/^\s*(?:\d+(?:\.\d+)?|\.\d+)\s*(px|%|vw|vh|vmin|vmax|rem|em)\s*$/i', $value, $matches ) ) {
        return '';
    }

    $number = preg_replace( '/\s*(?:px|%|vw|vh|vmin|vmax|rem|em)\s*$/i', '', trim( $value ) );
    return $number . strtolower( $matches[1] );
}

function asfx_sanitize_aspect_ratio( $value ) {
    if ( ! is_string( $value ) || ! preg_match( '/^\s*((?:\d+(?:\.\d+)?|\.\d+))\s*\/\s*((?:\d+(?:\.\d+)?|\.\d+))\s*$/', $value, $matches ) ) {
        return '';
    }

    if ( (float) $matches[1] <= 0 || (float) $matches[2] <= 0 ) {
        return '';
    }

    return $matches[1] . ' / ' . $matches[2];
}

function asfx_sanitize_spacing_value( $value, $allow_auto = false ) {
    if ( ! is_string( $value ) ) {
        return '';
    }

    $value = trim( $value );
    if ( $allow_auto && 'auto' === strtolower( $value ) ) {
        return 'auto';
    }

    if ( preg_match( '/^-?(?:\d+(?:\.\d+)?|\.\d+)(?:px|%|vw|vh|vmin|vmax|rem|em)$/i', $value ) ) {
        return strtolower( $value );
    }

    return preg_match( '/^-?0(?:\.0+)?$/', $value ) ? '0' : '';
}

function asfx_render( $attributes, $content ) {
    $defaults = array(
        'effect'        => 'wave',
        'intensity'     => 50,
        'speed'         => 50,
        'minHeight'     => 400,
        'width'         => '100%',
        'aspectRatio'   => '',
        'marginTop'     => '',
        'marginRight'   => '',
        'marginBottom'  => '',
        'marginLeft'    => '',
        'paddingTop'    => '',
        'paddingRight'  => '',
        'paddingBottom' => '',
        'paddingLeft'   => '',
        'dim'           => 35,
        'backgroundUrl' => '',
    );
    $attrs = wp_parse_args( $attributes, $defaults );

    $effect    = sanitize_key( $attrs['effect'] );
    $intensity = max( 0, min( 100, (int) $attrs['intensity'] ) );
    $speed     = max( 0, min( 100, (int) $attrs['speed'] ) );
    $dim       = max( 0, min( 90, (int) $attrs['dim'] ) );
    $min_h     = max( 0, (int) $attrs['minHeight'] );
    $width     = asfx_sanitize_width( $attrs['width'] );
    $aspect_ratio = asfx_sanitize_aspect_ratio( $attrs['aspectRatio'] );
    $legacy_style = isset( $attrs['style'] ) && is_array( $attrs['style'] )
        ? $attrs['style']
        : array();
    $legacy_dimensions = isset( $legacy_style['dimensions'] ) && is_array( $legacy_style['dimensions'] )
        ? $legacy_style['dimensions']
        : array();
    if ( '' === $aspect_ratio && isset( $legacy_dimensions['aspectRatio'] ) ) {
        $aspect_ratio = asfx_sanitize_aspect_ratio( $legacy_dimensions['aspectRatio'] );
    }
    if ( '100%' === $attrs['width'] && isset( $legacy_dimensions['width'] ) ) {
        $legacy_width = asfx_sanitize_width( $legacy_dimensions['width'] );
        if ( '' !== $legacy_width ) {
            $width = $legacy_width;
        }
    }
    $min_height = $min_h > 0 ? $min_h . 'px' : '';
    if ( 400 === $min_h && isset( $legacy_dimensions['minHeight'] ) ) {
        $legacy_min_height = asfx_sanitize_width( $legacy_dimensions['minHeight'] );
        if ( '' !== $legacy_min_height ) {
            $min_height = $legacy_min_height;
        }
    }
    $img_url   = esc_url_raw( $attrs['backgroundUrl'] );
    if ( '' !== $img_url && 'none' !== $effect ) {
        wp_enqueue_script( 'asfx-front' );
        wp_enqueue_style( 'asfx-front-style' );
    }

    $class = 'asfx-section asfx-effect-' . $effect;
    if ( '' !== $aspect_ratio ) {
        $class .= ' asfx-has-ratio';
    }

    $spacing_properties = array(
        'marginTop'     => array( 'margin-top', true ),
        'marginRight'   => array( 'margin-right', true ),
        'marginBottom'  => array( 'margin-bottom', true ),
        'marginLeft'    => array( 'margin-left', true ),
        'paddingTop'    => array( 'padding-top', false ),
        'paddingRight'  => array( 'padding-right', false ),
        'paddingBottom' => array( 'padding-bottom', false ),
        'paddingLeft'   => array( 'padding-left', false ),
    );
    $spacing_styles = '';
    foreach ( $spacing_properties as $attribute => $property ) {
        $value = asfx_sanitize_spacing_value( $attrs[ $attribute ], $property[1] );
        if ( '' !== $value ) {
            $spacing_styles .= $property[0] . ':' . $value . ';';
        }
    }

    $style = sprintf(
        '--asfx-dim:%.2f;%s%s%s',
        $dim / 100,
        '' !== $width && '100%' !== $width ? sprintf( 'width:%s;', $width ) : '',
        '' !== $aspect_ratio ? sprintf( 'aspect-ratio:%s;', $aspect_ratio ) : '',
        '' !== $aspect_ratio ? '' : ( '' !== $min_height ? sprintf( 'min-height:%s;', $min_height ) : '' )
    ) . $spacing_styles;

    $wrapper_attributes = get_block_wrapper_attributes(
        array(
            'class' => $class,
            'style' => $style,
        )
    );
    $data = sprintf(
        'data-asfx-effect="%s" data-asfx-intensity="%d" data-asfx-speed="%d" data-asfx-src="%s"',
        esc_attr( $effect ),
        $intensity,
        $speed,
        esc_attr( $img_url )
    );

    $canvas = '';
    if ( '' !== $img_url && 'none' !== $effect ) {
        $canvas = sprintf(
            '<canvas class="asfx-canvas" aria-hidden="true"></canvas>' .
            '<div class="asfx-overlay" aria-hidden="true"></div>' .
            '<img class="asfx-fallback" src="%s" alt="" style="position:absolute;inset:0;width:100%%;height:100%%;object-fit:cover;display:none" />',
            esc_url( $img_url )
        );
    }

    return sprintf(
        '<div %1$s %2$s>%3$s%4$s</div>',
        $wrapper_attributes,
        $data,
        $canvas,
        $content
    );
}