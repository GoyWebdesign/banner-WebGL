<?php
/**
 * Plugin Name: Animated Section FX
 * Description: Bloc Gutenberg conteneur (flex) avec image d'arrière-plan animée en WebGL (wave, ripple, displace). Sans build : aucun npm/node requis. Compatible WordPress 6.5+, testé 7.1 / 7.2-beta.
 * Version: 0.2.4
 * Plugin URI: https://github.com/GoyWebdesign/banner-WebGL
 * Update URI: https://github.com/GoyWebdesign/banner-WebGL
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: animated-section-fx
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'ASFX_VERSION', '0.2.4' );
define( 'ASFX_URL', plugin_dir_url( __FILE__ ) );
define( 'ASFX_GITHUB_REPO', 'https://github.com/GoyWebdesign/banner-WebGL' );
define( 'ASFX_GITHUB_API', 'https://api.github.com/repos/GoyWebdesign/banner-WebGL/releases/latest' );

/**
 * Récupère la dernière release stable publiée sur GitHub.
 *
 * @return array|WP_Error Données de release ou erreur HTTP/JSON.
 */
function asfx_get_github_release() {
    $cached = get_transient( 'asfx_github_release' );
    if ( false !== $cached ) {
        return $cached;
    }

    $cached_error = get_transient( 'asfx_github_update_error' );
    if ( false !== $cached_error ) {
        return new WP_Error( 'asfx_github_cached_error', $cached_error );
    }

    $response = wp_remote_get(
        ASFX_GITHUB_API,
        array(
            'timeout' => 10,
            'headers' => array(
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'Animated-Section-FX/' . ASFX_VERSION . '; ' . home_url( '/' ),
            ),
        )
    );

    if ( is_wp_error( $response ) ) {
        set_transient( 'asfx_github_update_error', $response->get_error_message(), HOUR_IN_SECONDS );
        return $response;
    }

    $status = wp_remote_retrieve_response_code( $response );
    if ( 200 !== $status ) {
        $message = 404 === $status
            ? 'Dépôt ou release stable GitHub introuvable. Vérifiez que le dépôt est public et qu’une release est publiée.'
            : sprintf( 'GitHub a répondu avec le code HTTP %d.', $status );
        set_transient( 'asfx_github_update_error', $message, HOUR_IN_SECONDS );
        return new WP_Error( 'asfx_github_http_error', $message );
    }

    $release = json_decode( wp_remote_retrieve_body( $response ), true );
    if (
        ! is_array( $release )
        || empty( $release['tag_name'] )
        || empty( $release['zipball_url'] )
        || ! empty( $release['draft'] )
        || ! empty( $release['prerelease'] )
    ) {
        $message = 'La réponse GitHub ne contient pas de release stable exploitable.';
        set_transient( 'asfx_github_update_error', $message, HOUR_IN_SECONDS );
        return new WP_Error( 'asfx_github_invalid_release', $message );
    }

    $version = preg_replace( '/^v/i', '', sanitize_text_field( $release['tag_name'] ) );
    if ( ! preg_match( '/^\d+(?:\.\d+){1,3}(?:[-+][0-9A-Za-z.-]+)?$/', $version ) ) {
        $message = 'Le tag de release GitHub ne contient pas une version valide.';
        set_transient( 'asfx_github_update_error', $message, HOUR_IN_SECONDS );
        return new WP_Error( 'asfx_github_invalid_version', $message );
    }

    $data = array(
        'version'     => $version,
        'package'     => esc_url_raw( $release['zipball_url'] ),
        'url'         => esc_url_raw( isset( $release['html_url'] ) ? $release['html_url'] : ASFX_GITHUB_REPO . '/releases' ),
        'description' => isset( $release['body'] ) ? sanitize_textarea_field( $release['body'] ) : '',
        'published'   => isset( $release['published_at'] ) ? sanitize_text_field( $release['published_at'] ) : '',
    );

    delete_transient( 'asfx_github_update_error' );
    set_transient( 'asfx_github_release', $data, 6 * HOUR_IN_SECONDS );
    return $data;
}

/**
 * Signale sur la page des extensions si GitHub ne permet pas de vérifier les releases.
 */
function asfx_github_update_admin_notice() {
    if ( ! current_user_can( 'update_plugins' ) || ! function_exists( 'get_current_screen' ) ) {
        return;
    }

    $screen = get_current_screen();
    $error = get_transient( 'asfx_github_update_error' );
    if ( ! $screen || 'plugins' !== $screen->id || false === $error ) {
        return;
    }

    printf(
        '<div class="notice notice-warning"><p>%s</p></div>',
        esc_html( 'Animated Section FX : mise à jour GitHub non vérifiée. ' . $error )
    );
}
add_action( 'admin_notices', 'asfx_github_update_admin_notice' );

/**
 * Ajoute la release GitHub aux mises à jour proposées par WordPress.
 *
 * @param object $transient Transient des mises à jour de plugins.
 * @return object
 */
function asfx_check_for_plugin_update( $transient ) {
    if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
        return $transient;
    }

    $release = asfx_get_github_release();
    if ( is_wp_error( $release ) ) {
        return $transient;
    }

    $plugin_file = plugin_basename( __FILE__ );
    if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
        $transient->response = array();
    }
    if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
        $transient->no_update = array();
    }

    if ( version_compare( $release['version'], ASFX_VERSION, '>' ) ) {
        $transient->response[ $plugin_file ] = (object) array(
            'slug'        => dirname( $plugin_file ),
            'plugin'      => $plugin_file,
            'new_version' => $release['version'],
            'url'         => $release['url'],
            'package'     => $release['package'],
        );
    } else {
        $transient->no_update[ $plugin_file ] = (object) array(
            'slug'        => dirname( $plugin_file ),
            'plugin'      => $plugin_file,
            'new_version' => ASFX_VERSION,
            'url'         => ASFX_GITHUB_REPO,
            'package'     => '',
        );
    }

    return $transient;
}
add_filter( 'pre_set_site_transient_update_plugins', 'asfx_check_for_plugin_update' );

/**
 * Affiche les informations GitHub dans la fenêtre « Détails du plugin ».
 *
 * @param false|object|WP_Error $result Résultat courant.
 * @param string                $action Action demandée par WordPress.
 * @param object                $args   Paramètres de la requête.
 * @return false|object|WP_Error
 */
function asfx_plugin_information( $result, $action, $args ) {
    if ( 'plugin_information' !== $action || empty( $args->slug ) || dirname( plugin_basename( __FILE__ ) ) !== $args->slug ) {
        return $result;
    }

    $release = asfx_get_github_release();
    if ( is_wp_error( $release ) ) {
        return $result;
    }

    return (object) array(
        'name'          => 'Animated Section FX',
        'slug'          => dirname( plugin_basename( __FILE__ ) ),
        'version'       => $release['version'],
        'author'        => '<a href="' . esc_url( ASFX_GITHUB_REPO ) . '">GoyWebdesign</a>',
        'homepage'      => ASFX_GITHUB_REPO,
        'download_link' => $release['package'],
        'sections'      => array(
            'description' => 'Bloc Gutenberg conteneur avec image d’arrière-plan animée en WebGL.',
            'changelog'   => $release['description'] ? nl2br( esc_html( $release['description'] ) ) : 'Voir les notes de version sur GitHub.',
        ),
        'last_updated'  => $release['published'],
        'requires'      => '6.5',
        'requires_php'  => '7.4',
    );
}
add_filter( 'plugins_api', 'asfx_plugin_information', 10, 3 );

/**
 * Ajoute un lien direct vers le dépôt dans la liste des extensions.
 *
 * @param array  $links      Liens de métadonnées existants.
 * @param string $plugin_file Fichier du plugin.
 * @return array
 */
function asfx_plugin_row_meta( $links, $plugin_file ) {
    if ( plugin_basename( __FILE__ ) === $plugin_file ) {
        $links[] = '<a href="' . esc_url( ASFX_GITHUB_REPO ) . '">Dépôt GitHub</a>';
    }
    return $links;
}
add_filter( 'plugin_row_meta', 'asfx_plugin_row_meta', 10, 2 );

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
                'preset'         => array( 'type' => 'string', 'default' => 'standard' ),
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
        'preset'        => 'standard',
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
    $preset    = sanitize_key( $attrs['preset'] );
    if ( ! in_array( $preset, array( 'soft', 'standard', 'strong' ), true ) ) {
        $preset = 'standard';
    }
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
        'data-asfx-effect="%s" data-asfx-preset="%s" data-asfx-intensity="%d" data-asfx-speed="%d" data-asfx-src="%s"',
        esc_attr( $effect ),
        esc_attr( $preset ),
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