/**
 * Animated Section FX — script d'éditeur SANS BUILD.
 * Vanilla JS + wp.element.createElement (pas de JSX, pas de npm).
 * Enregistré côté PHP via wp_enqueue_script avec les dépendances wp-*.
 */
( function ( wp ) {
    'use strict';

    var el = wp.element.createElement;
    var registerBlockType = wp.blocks.registerBlockType;
    var useBlockProps = wp.blockEditor.useBlockProps;
    var useInnerBlocksProps = wp.blockEditor.useInnerBlocksProps;
    var InnerBlocks = wp.blockEditor.InnerBlocks;
    var InspectorControls = wp.blockEditor.InspectorControls;
    var MediaUpload = wp.blockEditor.MediaUpload;
    var MediaUploadCheck = wp.blockEditor.MediaUploadCheck;
    var PanelBody = wp.components.PanelBody;
    var SelectControl = wp.components.SelectControl;
    var RangeControl = wp.components.RangeControl;
    var TextControl = wp.components.TextControl;
    var Button = wp.components.Button;
    var Placeholder = wp.components.Placeholder;
    var __ = wp.i18n.__;

    var EFFECTS = [
        { value: 'wave', label: __( 'Vague (déformation horizontale)', 'animated-section-fx' ) },
        { value: 'ripple', label: __( 'Ripple (ondes concentriques)', 'animated-section-fx' ) },
        { value: 'displace', label: __( 'Displacement (bruit organique)', 'animated-section-fx' ) },
        { value: 'none', label: __( 'Aucun (image statique)', 'animated-section-fx' ) },
    ];

    var WIDTH_UNITS = [
        { value: 'px', label: 'px' },
        { value: '%', label: '%' },
        { value: 'vw', label: 'vw' },
        { value: 'vh', label: 'vh' },
        { value: 'vmin', label: 'vmin' },
        { value: 'vmax', label: 'vmax' },
        { value: 'rem', label: 'rem' },
        { value: 'em', label: 'em' },
    ];

    function getWidthParts( value ) {
        var match = String( value || '100%' ).match( /^\s*((?:\d+(?:\.\d+)?|\.\d+))\s*(px|%|vw|vh|vmin|vmax|rem|em)\s*$/i );
        return match
            ? { number: match[ 1 ], unit: match[ 2 ].toLowerCase() }
            : { number: '100', unit: '%' };
    }

    function normalizeAspectRatio( value ) {
        var match = String( value || '' ).match( /^\s*((?:\d+(?:\.\d+)?|\.\d+))\s*\/\s*((?:\d+(?:\.\d+)?|\.\d+))\s*$/ );
        return match && Number( match[ 1 ] ) > 0 && Number( match[ 2 ] ) > 0
            ? match[ 1 ] + ' / ' + match[ 2 ]
            : '';
    }

    function clearLegacyDimension( attributes, name ) {
        var style = attributes.style || {};
        var dimensions = style.dimensions || {};
        var updatedDimensions = Object.assign( {}, dimensions );
        delete updatedDimensions[ name ];

        var updatedStyle = Object.assign( {}, style );
        if ( Object.keys( updatedDimensions ).length ) {
            updatedStyle.dimensions = updatedDimensions;
        } else {
            delete updatedStyle.dimensions;
        }

        return { style: updatedStyle };
    }

    function sanitizeSpacingValue( value, allowAuto ) {
        var normalized = String( value || '' ).trim();
        if ( allowAuto && normalized.toLowerCase() === 'auto' ) {
            return 'auto';
        }
        if ( /^-?(?:\d+(?:\.\d+)?|\.\d+)(?:px|%|vw|vh|vmin|vmax|rem|em)$/i.test( normalized ) ) {
            return normalized;
        }
        return /^-?0(?:\.0+)?$/.test( normalized ) ? '0' : '';
    }

    function getSpacingStyle( attributes ) {
        var properties = {
            marginTop: [ 'marginTop', true ],
            marginRight: [ 'marginRight', true ],
            marginBottom: [ 'marginBottom', true ],
            marginLeft: [ 'marginLeft', true ],
            paddingTop: [ 'paddingTop', false ],
            paddingRight: [ 'paddingRight', false ],
            paddingBottom: [ 'paddingBottom', false ],
            paddingLeft: [ 'paddingLeft', false ],
        };
        var style = {};
        Object.keys( properties ).forEach( function ( key ) {
            var value = sanitizeSpacingValue( attributes[ key ], properties[ key ][ 1 ] );
            if ( value ) {
                style[ properties[ key ][ 0 ] ] = value;
            }
        } );
        return style;
    }

    function spacingControl( label, attribute, attributes, setAttributes, allowAuto ) {
        var value = attributes[ attribute ] || '';
        var validValue = ! value || !! sanitizeSpacingValue( value, allowAuto );
        return el( TextControl, {
            label: label,
            value: value,
            placeholder: '0px',
            help: validValue
                ? ( allowAuto
                    ? __( 'Unités : px, %, vw, vh, vmin, vmax, rem, em; auto est aussi accepté.', 'animated-section-fx' )
                    : __( 'Unités : px, %, vw, vh, vmin, vmax, rem, em.', 'animated-section-fx' ) )
                : __( 'Valeur invalide. Utilisez une valeur numérique avec une unité autorisée.', 'animated-section-fx' ),
            onChange: function ( value ) {
                setAttributes( { [ attribute ]: value.trim() } );
            },
        } );
    }

    function Edit( props ) {
        var attributes = props.attributes;
        var setAttributes = props.setAttributes;
        var savedDimensions = attributes.style && attributes.style.dimensions
            ? attributes.style.dimensions
            : {};
        var width = attributes.width || '100%';
        if ( width === '100%' && savedDimensions.width ) {
            width = savedDimensions.width;
        }
        var widthParts = getWidthParts( width );
        var aspectRatio = normalizeAspectRatio( attributes.aspectRatio || savedDimensions.aspectRatio );
        var minHeight = attributes.minHeight;
        if ( minHeight === 400 && savedDimensions.minHeight ) {
            var savedMinHeight = String( savedDimensions.minHeight ).match( /^\s*(\d+(?:\.\d+)?)px\s*$/i );
            if ( savedMinHeight ) {
                minHeight = Number( savedMinHeight[ 1 ] );
            }
        }

        var editorStyle = Object.assign( {
            position: 'relative',
            width: width === '100%' ? undefined : width,
            aspectRatio: aspectRatio || undefined,
            minHeight: aspectRatio ? undefined : ( minHeight ? minHeight + 'px' : undefined ),
            boxSizing: 'border-box',
        }, getSpacingStyle( attributes ) );
        editorStyle.background = attributes.backgroundUrl
            ? 'linear-gradient(rgba(0,0,0,' + ( attributes.dim / 100 ) + '), rgba(0,0,0,' + ( attributes.dim / 100 ) + ')), url(' + attributes.backgroundUrl + ') center/cover'
            : undefined;

        var blockProps = useBlockProps( {
            className: 'asfx-section asfx-editor' + ( aspectRatio ? ' asfx-has-ratio' : '' ),
            style: editorStyle,
        } );
        var innerBlocksProps = useInnerBlocksProps( blockProps, {
            templateLock: false,
            renderAppender: InnerBlocks.ButtonBlockAppender,
        } );

        var inspector = el(
            InspectorControls,
            {},
            el(
                PanelBody,
                {
                    title: __( "Image d'arrière-plan", 'animated-section-fx' ),
                    initialOpen: true,
                },
                el(
                    MediaUploadCheck,
                    {},
                    el( MediaUpload, {
                        onSelect: function ( media ) {
                            setAttributes( {
                                backgroundId: media.id,
                                backgroundUrl: media.url,
                            } );
                        },
                        allowedTypes: [ 'image' ],
                        value: attributes.backgroundId,
                        render: function ( renderProps ) {
                            return el(
                                'div',
                                { style: { display: 'flex', gap: '8px' } },
                                el(
                                    Button,
                                    { variant: 'secondary', onClick: renderProps.open },
                                    attributes.backgroundUrl
                                        ? __( "Remplacer l'image", 'animated-section-fx' )
                                        : __( 'Choisir une image', 'animated-section-fx' )
                                ),
                                attributes.backgroundUrl
                                    ? el(
                                            Button,
                                            {
                                                variant: 'tertiary',
                                                isDestructive: true,
                                                onClick: function () {
                                                    setAttributes( { backgroundId: 0, backgroundUrl: '' } );
                                                },
                                            },
                                            __( 'Retirer', 'animated-section-fx' )
                                        )
                                    : null
                            );
                        },
                    } )
                ),
                el(
                    'p',
                    { style: { color: '#757575', marginTop: '8px' } },
                    __(
                        'Astuce : les images à fort contraste (cartes topographiques, traits blancs sur fond noir) donnent le meilleur rendu.',
                        'animated-section-fx'
                    )
                )
            ),
            el(
                PanelBody,
                { title: __( 'Animation', 'animated-section-fx' ), initialOpen: true },
                el( SelectControl, {
                    label: __( 'Effet', 'animated-section-fx' ),
                    value: attributes.effect,
                    options: EFFECTS,
                    onChange: function ( v ) {
                        setAttributes( { effect: v } );
                    },
                } ),
                el( RangeControl, {
                    label: __( 'Intensité', 'animated-section-fx' ),
                    value: attributes.intensity,
                    onChange: function ( v ) {
                        setAttributes( { intensity: v } );
                    },
                    min: 0,
                    max: 100,
                } ),
                el( RangeControl, {
                    label: __( 'Vitesse', 'animated-section-fx' ),
                    value: attributes.speed,
                    onChange: function ( v ) {
                        setAttributes( { speed: v } );
                    },
                    min: 0,
                    max: 100,
                } )
            ),
            el(
                PanelBody,
                { title: __( 'Mise en page', 'animated-section-fx' ) },
                el( TextControl, {
                    label: __( 'Largeur', 'animated-section-fx' ),
                    type: 'number',
                    min: 0,
                    step: 'any',
                    value: widthParts.number,
                    onChange: function ( value ) {
                        setAttributes( Object.assign(
                            { width: value + widthParts.unit },
                            clearLegacyDimension( attributes, 'width' )
                        ) );
                    },
                } ),
                el( SelectControl, {
                    label: __( 'Unité de largeur', 'animated-section-fx' ),
                    value: widthParts.unit,
                    options: WIDTH_UNITS,
                    onChange: function ( unit ) {
                        setAttributes( Object.assign(
                            { width: widthParts.number + unit },
                            clearLegacyDimension( attributes, 'width' )
                        ) );
                    },
                } ),
                el( TextControl, {
                    label: __( 'Ratio (largeur / hauteur)', 'animated-section-fx' ),
                    help: __( 'Exemple : 16 / 9. Laisse vide pour utiliser la hauteur minimale.', 'animated-section-fx' ),
                    value: aspectRatio,
                    placeholder: '16 / 9',
                    onChange: function ( value ) {
                        setAttributes( Object.assign(
                            { aspectRatio: value.trim() },
                            clearLegacyDimension( attributes, 'aspectRatio' )
                        ) );
                    },
                } ),
                el( RangeControl, {
                    label: __( 'Hauteur minimale (px)', 'animated-section-fx' ),
                    value: minHeight,
                    onChange: function ( v ) {
                        setAttributes( Object.assign(
                            { minHeight: v },
                            clearLegacyDimension( attributes, 'minHeight' )
                        ) );
                    },
                    min: 0,
                    max: 1200,
                    step: 20,
                    disabled: !! aspectRatio,
                    help: aspectRatio
                        ? __( 'Désactivée lorsque le ratio est défini.', 'animated-section-fx' )
                        : undefined,
                } ),
                el( RangeControl, {
                    label: __( 'Voile sombre (%)', 'animated-section-fx' ),
                    value: attributes.dim,
                    onChange: function ( v ) {
                        setAttributes( { dim: v } );
                    },
                    min: 0,
                    max: 90,
                } )
            ),
            el(
                PanelBody,
                { title: __( 'Marges et padding', 'animated-section-fx' ) },
                spacingControl( __( 'Marge haute', 'animated-section-fx' ), 'marginTop', attributes, setAttributes, true ),
                spacingControl( __( 'Marge droite', 'animated-section-fx' ), 'marginRight', attributes, setAttributes, true ),
                spacingControl( __( 'Marge basse', 'animated-section-fx' ), 'marginBottom', attributes, setAttributes, true ),
                spacingControl( __( 'Marge gauche', 'animated-section-fx' ), 'marginLeft', attributes, setAttributes, true ),
                spacingControl( __( 'Padding haut', 'animated-section-fx' ), 'paddingTop', attributes, setAttributes, false ),
                spacingControl( __( 'Padding droite', 'animated-section-fx' ), 'paddingRight', attributes, setAttributes, false ),
                spacingControl( __( 'Padding bas', 'animated-section-fx' ), 'paddingBottom', attributes, setAttributes, false ),
                spacingControl( __( 'Padding gauche', 'animated-section-fx' ), 'paddingLeft', attributes, setAttributes, false )
            )
        );

        var section = attributes.backgroundUrl
            ? el( 'div', innerBlocksProps )
            : el(
                'div',
                blockProps,
                el(
                    Placeholder,
                    {
                        icon: 'format-image',
                        label: __( 'Section animée', 'animated-section-fx' ),
                        instructions: __(
                            "Choisissez une image d'arrière-plan dans le panneau des réglages pour activer l'animation WebGL.",
                            'animated-section-fx'
                        ),
                    }
                )
            );

        return el(
            wp.element.Fragment,
            {},
            inspector,
            section
        );
    }

    registerBlockType( 'asfx/animated-section', {
        apiVersion: 3,
        title: __( 'Section animée (WebGL)', 'animated-section-fx' ),
        category: 'design',
        icon: 'format-image',
        description: __(
            'Section conteneur avec image d\u2019arrière-plan animée en WebGL.',
            'animated-section-fx'
        ),
        keywords: [ 'section', 'fond animé', 'webgl', 'conteneur' ],
        attributes: {
            backgroundId: { type: 'number' },
            backgroundUrl: { type: 'string', default: '' },
            effect: { type: 'string', default: 'wave' },
            intensity: { type: 'number', default: 50 },
            speed: { type: 'number', default: 50 },
            minHeight: { type: 'number', default: 400 },
            width: { type: 'string', default: '100%' },
            aspectRatio: { type: 'string', default: '' },
            marginTop: { type: 'string', default: '' },
            marginRight: { type: 'string', default: '' },
            marginBottom: { type: 'string', default: '' },
            marginLeft: { type: 'string', default: '' },
            paddingTop: { type: 'string', default: '' },
            paddingRight: { type: 'string', default: '' },
            paddingBottom: { type: 'string', default: '' },
            paddingLeft: { type: 'string', default: '' },
            dim: { type: 'number', default: 35 },
        },
        supports: {
            layout: {
                allowSwitching: true,
                allowEditing: true,
                allowJustification: true,
                allowVerticalAlignment: true,
                allowSizingOnChildren: true,
                default: { type: 'flex' },
            },
            align: [ 'wide', 'full' ],
            anchor: true,
            color: { text: true, background: true },
            spacing: { padding: true, margin: true, blockGap: true },
            typography: { fontSize: true, lineHeight: true },
            html: false,
            innerBlocks: true,
        },
        edit: Edit,
        // Bloc dynamique : le conteneur est rendu en PHP (asfx_render),
        // InnerBlocks.Content sérialise le contenu.
        save: function () {
            return el( InnerBlocks.Content );
        },
    } );
} )( window.wp );