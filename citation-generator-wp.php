<?php
/**
 * Plugin Name: Generador de Citas Bibliográficas Profesional
 * Plugin URI: https://biblioteca.utmachala.edu.ec
 * Description: Genera citas bibliográficas en formatos académicos (APA 7, IEEE, Vancouver, Chicago 18 - Notes&Bibliography y Author-Date) usando DOI, ISBN, ISSN, URL o de forma manual.
 * Version: 1.2.1
 * Author: Anthony Lima
 * License: GPL2
 *
 * CHANGELOG v1.2.0 (auditoría de conformidad normativa):
 * - IEEE: autores truncados a "Primero et al." con 7+ autores (antes listaba a todos).
 * - APA7: elipsis de 21+ autores corregida a puntos suspensivos espaciados ". . .".
 * - APA7: se agregan cita narrativa y cita parentética (antes solo existía la referencia).
 * - APA7: se agrega tipo de fuente "IA generativa" (autor = compañía, sin fecha de recuperación).
 * - Vancouver: se agregan campos manuales para abreviatura NLM de revista y PMID (nunca inferidos automáticamente).
 * - Chicago 18: se separa en sus dos sistemas oficiales obligatorios:
 *     Notes & Bibliography -> nota completa, nota abreviada, bibliografía.
 *     Author-Date           -> cita en texto, referencia.
 *   (cgwp_format_chicago() se conserva como alias de cgwp_format_chicago_bibliography() por compatibilidad).
 */

// Evitar acceso directo
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// --------------------------------------------------------------------
// 1. REGISTRO DE ENDPOINTS REST API
// --------------------------------------------------------------------

/**
 * Registra la ruta REST API del generador de citas.
 * Ruta: POST /wp-json/citation-generator/v1/generate
 */
add_action( 'rest_api_init', 'cgwp_register_routes' );

function cgwp_register_routes() {
    register_rest_route( 'citation-generator/v1', '/generate', array(
        'methods'             => 'POST',
        'callback'            => 'cgwp_handle_generate',
        'permission_callback' => '__return_true',
    ));
}

/**
 * Controlador principal de la REST API.
 * Procesa la solicitud, verifica la caché en Transients, consulta los resolutores
 * de metadatos (DOI/ISBN/ISSN/URL/Manual) y ejecuta todos los formateadores de citas.
 */
function cgwp_handle_generate( WP_REST_Request $request ) {
    $params = $request->get_json_params();

    $type        = isset( $params['type'] ) ? sanitize_text_field( $params['type'] ) : 'doi';
    $value       = isset( $params['value'] ) ? sanitize_text_field( $params['value'] ) : '';
    $lang        = isset( $params['lang'] ) ? sanitize_text_field( $params['lang'] ) : 'es';
    $manual_meta = isset( $params['metadata'] ) ? $params['metadata'] : null;
    $source_type = isset( $params['sourceType'] ) ? sanitize_text_field( $params['sourceType'] ) : 'journal_article';

    if ( empty( $value ) && $type !== 'manual' ) {
        return new WP_REST_Response( array( 'success' => false, 'error' => 'El identificador es requerido.' ), 400 );
    }

    $metadata = null;
    $error = null;

    // Sistema de Caché con Transients de WordPress
    $cache_key = 'cgwp_' . md5( $type . '_' . $value );
    if ( $type !== 'manual' ) {
        $cached_metadata = get_transient( $cache_key );
        if ( $cached_metadata ) {
            $metadata = $cached_metadata;
        }
    }

    // Si no está en caché, resolver de forma remota
    if ( ! $metadata ) {
        switch ( $type ) {
            case 'doi':
                $resolved = cgwp_resolve_doi( $value );
                if ( $resolved['found'] ) {
                    $metadata = $resolved['metadata'];
                    $source_type = 'journal_article';
                    set_transient( $cache_key, $metadata, DAY_IN_SECONDS );
                } else {
                    $error = isset( $resolved['error'] ) ? $resolved['error'] : 'DOI no encontrado.';
                }
                break;

            case 'isbn':
                $resolved = cgwp_resolve_isbn( $value );
                if ( $resolved['found'] ) {
                    $metadata = $resolved['metadata'];
                    $source_type = 'book';
                    set_transient( $cache_key, $metadata, DAY_IN_SECONDS );
                } else {
                    $error = 'ISBN no encontrado.';
                }
                break;

            case 'issn':
                $resolved = cgwp_resolve_issn( $value );
                if ( $resolved['found'] ) {
                    $metadata = $resolved['metadata'];
                    $source_type = 'journal_article';
                    set_transient( $cache_key, $metadata, DAY_IN_SECONDS );
                } else {
                    $error = isset( $resolved['error'] ) ? $resolved['error'] : 'ISSN no encontrado.';
                }
                break;

            case 'url':
                $resolved = cgwp_resolve_url( $value );
                if ( $resolved['found'] ) {
                    $metadata = $resolved['metadata'];
                    $source_type = 'website';
                    set_transient( $cache_key, $metadata, DAY_IN_SECONDS );
                } else {
                    $error = isset( $resolved['error'] ) ? $resolved['error'] : 'No se pudo extraer metadatos del enlace.';
                }
                break;

            case 'manual':
                if ( $manual_meta ) {
                    $metadata = cgwp_sanitize_manual_metadata( $manual_meta );
                } else {
                    $error = 'Faltan metadatos manuales.';
                }
                break;
        }
    }

    if ( ! $metadata ) {
        return new WP_REST_Response( array( 'success' => false, 'error' => $error ), 404 );
    }

    // Inyectar lenguaje y tipo para los formateadores
    $metadata['lang'] = $lang;
    $metadata['sourceType'] = $source_type;

    // Generar TODOS los formatos a la vez
    $formats = array(
        'apa6', 'apa7', 'apa7_narrative', 'apa7_parenthetical', 'harvard',
        'chicago', 'chicago_bibliography', 'chicago_note_full', 'chicago_note_short',
        'chicago_authordate_intext', 'chicago_authordate_reference',
        'turabian', 'ieee',
        'vancouver', 'abnt', 'cse', 'asa', 'apsa', 'aaa', 'ama', 'mla', 'bibtex', 'ris'
    );

    $citations = array();
    foreach ( $formats as $format ) {
        $func = 'cgwp_format_' . $format;
        if ( function_exists( $func ) ) {
            $citations[$format] = $func( $metadata );
        }
    }

    return new WP_REST_Response( array(
        'success'   => true,
        'citations' => $citations,
        'metadata'  => $metadata
    ), 200 );
}

// --------------------------------------------------------------------
// 2. RESOLUTORES DE METADATOS (DOI, ISBN, ISSN, URL, MANUAL)
// --------------------------------------------------------------------

/**
 * Resolutor DOI: Consulta la API pública de Crossref
 * y utiliza la API de DataCite como fallback secundario.
 */
function cgwp_resolve_doi( $doi ) {
    $doi = trim( $doi );
    if ( preg_match( '/(10\.\d{4,9}\/[-._;()\/:\w]+)/', $doi, $matches ) ) {
        $doi = $matches[1];
    } else {
        return array( 'found' => false, 'error' => 'Formato de DOI inválido.' );
    }

    $crossref_url = 'https://api.crossref.org/works/' . urlencode( $doi );
    $response = wp_remote_get( $crossref_url, array( 'timeout' => 8 ) );

    if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( isset( $body['message'] ) ) {
            $item = $body['message'];

            $authors = array();
            if ( isset( $item['author'] ) && is_array( $item['author'] ) ) {
                foreach ( $item['author'] as $auth ) {
                    if ( isset( $auth['family'] ) ) {
                        $given = isset( $auth['given'] ) ? $auth['given'] : '';
                        $first_parts = explode(' ', $given);
                        $firstName = isset($first_parts[0]) ? $first_parts[0] : '';
                        $middleName = count($first_parts) > 1 ? implode(' ', array_slice($first_parts, 1)) : '';

                        $family = $auth['family'];
                        $family_parts = explode(' ', $family);
                        $lastName = isset($family_parts[0]) ? $family_parts[0] : '';
                        $secondLastName = count($family_parts) > 1 ? implode(' ', array_slice($family_parts, 1)) : '';

                        $authors[] = array(
                            'firstName'      => $firstName,
                            'middleName'     => $middleName,
                            'lastName'       => $lastName,
                            'secondLastName' => $secondLastName,
                            'isCorporate'    => false
                        );
                    } elseif ( isset( $auth['name'] ) ) {
                        $authors[] = array(
                            'name'        => $auth['name'],
                            'isCorporate' => true
                        );
                    }
                }
            }

            $year = '';
            $date_obj = isset( $item['published-print'] ) ? $item['published-print'] : ( isset( $item['published-online'] ) ? $item['published-online'] : ( isset( $item['created'] ) ? $item['created'] : null ) );
            if ( $date_obj && isset( $date_obj['date-parts'][0][0] ) ) {
                $year = $date_obj['date-parts'][0][0];
            }

            $metadata = array(
                'authors'     => $authors,
                'title'       => isset( $item['title'][0] ) ? $item['title'][0] : '',
                'year'        => $year,
                'doi'         => $doi,
                'publisher'   => isset( $item['publisher'] ) ? $item['publisher'] : '',
                'journalName' => isset( $item['container-title'][0] ) ? $item['container-title'][0] : '',
                'volume'      => isset( $item['volume'] ) ? $item['volume'] : '',
                'issue'       => isset( $item['issue'] ) ? $item['issue'] : '',
                'pages'       => isset( $item['page'] ) ? $item['page'] : '',
                'url'         => 'https://doi.org/' . $doi
            );

            return array( 'found' => true, 'metadata' => $metadata );
        }
    }

    $datacite_url = 'https://api.datacite.org/dois/' . urlencode( $doi );
    $response = wp_remote_get( $datacite_url, array( 'timeout' => 8 ) );

    if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( isset( $body['data']['attributes'] ) ) {
            $attrs = $body['data']['attributes'];

            $authors = array();
            if ( isset( $attrs['creators'] ) && is_array( $attrs['creators'] ) ) {
                foreach ( $attrs['creators'] as $creator ) {
                    if ( isset( $creator['familyName'] ) ) {
                        $given = isset( $creator['givenName'] ) ? $creator['givenName'] : '';
                        $first_parts = explode(' ', $given);
                        $firstName = isset($first_parts[0]) ? $first_parts[0] : '';
                        $middleName = count($first_parts) > 1 ? implode(' ', array_slice($first_parts, 1)) : '';

                        $family = $creator['familyName'];
                        $family_parts = explode(' ', $family);
                        $lastName = isset($family_parts[0]) ? $family_parts[0] : '';
                        $secondLastName = count($family_parts) > 1 ? implode(' ', array_slice($family_parts, 1)) : '';

                        $authors[] = array(
                            'firstName'      => $firstName,
                            'middleName'     => $middleName,
                            'lastName'       => $lastName,
                            'secondLastName' => $secondLastName,
                            'isCorporate'    => false
                        );
                    } elseif ( isset( $creator['name'] ) ) {
                        $authors[] = array(
                            'name'        => $creator['name'],
                            'isCorporate' => true
                        );
                    }
                }
            }

            $metadata = array(
                'authors'   => $authors,
                'title'     => isset( $attrs['titles'][0]['title'] ) ? $attrs['titles'][0]['title'] : '',
                'year'      => isset( $attrs['publicationYear'] ) ? $attrs['publicationYear'] : '',
                'doi'       => $doi,
                'publisher' => isset( $attrs['publisher'] ) ? $attrs['publisher'] : '',
                'url'       => isset( $attrs['url'] ) ? $attrs['url'] : 'https://doi.org/' . $doi
            );

            return array( 'found' => true, 'metadata' => $metadata );
        }
    }

    return array( 'found' => false );
}

/**
 * Resolutor ISBN: Consulta la API pública de OpenLibrary para obtener metadatos de libros.
 */
function cgwp_resolve_isbn( $isbn ) {
    $isbn = preg_replace( '/[^0-9X]/i', '', $isbn );
    if ( empty( $isbn ) ) {
        return array( 'found' => false );
    }

    $url = "https://openlibrary.org/api/books?bibkeys=ISBN:{$isbn}&jscmd=data&format=json";
    $response = wp_remote_get( $url, array( 'timeout' => 8 ) );

    if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $key = "ISBN:{$isbn}";

        if ( isset( $body[$key] ) ) {
            $book = $body[$key];

            $authors = array();
            if ( isset( $book['authors'] ) && is_array( $book['authors'] ) ) {
                foreach ( $book['authors'] as $auth ) {
                    $parts = explode( ' ', trim( $auth['name'] ) );
                    if ( count( $parts ) > 1 ) {
                        $last = array_pop( $parts );
                        $first = implode( ' ', $parts );
                        $first_parts = explode(' ', $first);

                        $authors[] = array(
                            'firstName'      => isset($first_parts[0]) ? $first_parts[0] : '',
                            'middleName'     => count($first_parts) > 1 ? implode(' ', array_slice($first_parts, 1)) : '',
                            'lastName'       => $last,
                            'secondLastName' => '',
                            'isCorporate'    => false
                        );
                    } else {
                        $authors[] = array( 'name' => $auth['name'], 'isCorporate' => true );
                    }
                }
            }

            $year = '';
            if ( isset( $book['publish_date'] ) ) {
                if ( preg_match( '/\b\d{4}\b/', $book['publish_date'], $m ) ) {
                    $year = $m[0];
                }
            }

            $metadata = array(
                'authors'   => $authors,
                'title'     => isset( $book['title'] ) ? $book['title'] : '',
                'year'      => $year,
                'publisher' => isset( $book['publishers'][0]['name'] ) ? $book['publishers'][0]['name'] : '',
                'isbn'      => $isbn,
                'url'       => isset( $book['url'] ) ? $book['url'] : ''
            );

            return array( 'found' => true, 'metadata' => $metadata );
        }
    }

    return array( 'found' => false );
}

/**
 * Resolutor ISSN: Valida el formato e identifica revistas desde Crossref Journals.
 */
function cgwp_resolve_issn( $issn ) {
    $issn = trim( $issn );
    $issn = preg_replace( '/^issn(-l)?\s*/i', '', $issn );
    $issn = preg_replace( '/[^0-9X-]/i', '', $issn );

    if ( empty( $issn ) ) {
        return array( 'found' => false, 'error' => 'Por favor ingrese un ISSN válido.' );
    }

    if ( strpos( $issn, '-' ) === false && strlen( $issn ) === 8 ) {
        $issn = substr( $issn, 0, 4 ) . '-' . substr( $issn, 4 );
    }

    if ( ! preg_match( '/^\d{4}-\d{3}[\dXx]$/', $issn ) ) {
        return array( 'found' => false, 'error' => 'El formato del ISSN debe ser XXXX-XXXX.' );
    }

    $url = 'https://api.crossref.org/journals/' . urlencode( $issn );
    $response = wp_remote_get( $url, array( 'timeout' => 8 ) );

    if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( isset( $body['message'] ) ) {
            $item = $body['message'];

            $metadata = array(
                'journalName' => isset( $item['title'] ) ? $item['title'] : '',
                'publisher'   => isset( $item['publisher'] ) ? $item['publisher'] : '',
                'title'       => '',
                'year'        => '',
                'authors'     => array()
            );
            return array( 'found' => true, 'metadata' => $metadata );
        }
    }

    return array( 'found' => false, 'error' => 'No se encontraron metadatos para este ISSN. Si es una revista física, por favor ingrese los datos manualmente.' );
}

/**
 * Resolutor URL: Extrae metadatos HTML (etiquetas Meta y OpenGraph) mediante web scraping.
 */
function cgwp_resolve_url( $url ) {
    if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
        return array( 'found' => false, 'error' => 'Enlace URL inválido.' );
    }

    if ( preg_match( '/\.pdf$/i', $url ) ) {
        return array( 'found' => false, 'error' => 'Los enlaces directos a archivos PDF no contienen metadatos HTML legibles. Ingrese la información manualmente o use el DOI.' );
    }

    $response = wp_remote_get( $url, array( 'timeout' => 8, 'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)' ) );
    if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
        return array( 'found' => false, 'error' => 'No se pudo conectar con el sitio web o el sitio bloqueó la lectura automática.' );
    }

    $html = wp_remote_retrieve_body( $response );
    if ( empty( $html ) ) {
        return array( 'found' => false, 'error' => 'El sitio web cargó una página vacía.' );
    }

    $title = '';
    $website_name = '';
    $author_name = '';
    $year = date('Y');

    if ( preg_match( '/<title>(.*?)<\/title>/si', $html, $matches ) ) {
        $title = trim( html_entity_decode( $matches[1], ENT_QUOTES, 'UTF-8' ) );
    }

    if ( preg_match( '/<meta[^>]*property=["\']og:title["\'][^>]*content=["\'](.*?)["\']/si', $html, $matches ) ) {
        $title = trim( html_entity_decode( $matches[1], ENT_QUOTES, 'UTF-8' ) );
    }
    if ( preg_match( '/<meta[^>]*property=["\']og:site_name["\'][^>]*content=["\'](.*?)["\']/si', $html, $matches ) ) {
        $website_name = trim( html_entity_decode( $matches[1], ENT_QUOTES, 'UTF-8' ) );
    }
    if ( preg_match( '/<meta[^>]*name=["\']author["\'][^>]*content=["\'](.*?)["\']/si', $html, $matches ) ) {
        $author_name = trim( html_entity_decode( $matches[1], ENT_QUOTES, 'UTF-8' ) );
    }
    if ( preg_match( '/<meta[^>]*property=["\']article:published_time["\'][^>]*content=["\'](.*?)["\']/si', $html, $matches ) ) {
        if ( preg_match( '/\b\d{4}\b/', $matches[1], $yr ) ) {
            $year = $yr[0];
        }
    }

    $authors = array();
    if ( ! empty( $author_name ) ) {
        $parts = explode( ' ', $author_name );
        if ( count( $parts ) > 1 ) {
            $last = array_pop( $parts );
            $first = implode( ' ', $parts );
            $first_parts = explode(' ', $first);
            $authors[] = array(
                'firstName'      => isset($first_parts[0]) ? $first_parts[0] : '',
                'middleName'     => count($first_parts) > 1 ? implode(' ', array_slice($first_parts, 1)) : '',
                'lastName'       => $last,
                'secondLastName' => '',
                'isCorporate'    => false
            );
        } else {
            $authors[] = array( 'name' => $author_name, 'isCorporate' => true );
        }
    }

    $metadata = array(
        'authors'     => $authors,
        'title'       => $title,
        'year'        => $year,
        'websiteName' => $website_name,
        'url'         => $url
    );

    return array( 'found' => true, 'metadata' => $metadata );
}

/**
 * Sanitizador Manual: Valida y limpia los metadatos ingresados manualmente por el usuario.
 */
function cgwp_sanitize_manual_metadata( $meta ) {
    $sanitized = array();
    $sanitized['title'] = isset( $meta['title'] ) ? sanitize_text_field( $meta['title'] ) : 'Sin título';
    $sanitized['year'] = isset( $meta['year'] ) ? sanitize_text_field( $meta['year'] ) : '';
    $sanitized['publisher'] = isset( $meta['publisher'] ) ? sanitize_text_field( $meta['publisher'] ) : '';
    $sanitized['place'] = isset( $meta['place'] ) ? sanitize_text_field( $meta['place'] ) : '';
    $sanitized['journalName'] = isset( $meta['journalName'] ) ? sanitize_text_field( $meta['journalName'] ) : '';
    $sanitized['websiteName'] = isset( $meta['websiteName'] ) ? sanitize_text_field( $meta['websiteName'] ) : '';
    $sanitized['url'] = isset( $meta['url'] ) ? esc_url_raw( $meta['url'] ) : '';
    $sanitized['doi'] = isset( $meta['doi'] ) ? sanitize_text_field( $meta['doi'] ) : '';
    $sanitized['isbn'] = isset( $meta['isbn'] ) ? sanitize_text_field( $meta['isbn'] ) : '';
    $sanitized['volume'] = isset( $meta['volume'] ) ? sanitize_text_field( $meta['volume'] ) : '';
    $sanitized['issue'] = isset( $meta['issue'] ) ? sanitize_text_field( $meta['issue'] ) : '';
    $sanitized['pages'] = isset( $meta['pages'] ) ? sanitize_text_field( $meta['pages'] ) : '';
    $sanitized['bookTitle'] = isset( $meta['bookTitle'] ) ? sanitize_text_field( $meta['bookTitle'] ) : '';
    $sanitized['conferenceName'] = isset( $meta['conferenceName'] ) ? sanitize_text_field( $meta['conferenceName'] ) : '';
    $sanitized['institution'] = isset( $meta['institution'] ) ? sanitize_text_field( $meta['institution'] ) : '';
    $sanitized['degree'] = isset( $meta['degree'] ) ? sanitize_text_field( $meta['degree'] ) : '';
    $sanitized['newspaperName'] = isset( $meta['newspaperName'] ) ? sanitize_text_field( $meta['newspaperName'] ) : '';
    $sanitized['magazineName'] = isset( $meta['magazineName'] ) ? sanitize_text_field( $meta['magazineName'] ) : '';

    // --- Campos nuevos (auditoría de conformidad) ---
    // APA7 - IA generativa: versión del modelo (el autor/compañía viaja como authors[0], isCorporate=true)
    $sanitized['aiVersion'] = isset( $meta['aiVersion'] ) ? sanitize_text_field( $meta['aiVersion'] ) : '';
    // Vancouver - ICMJE: abreviatura NLM y PMID, SIEMPRE de entrada manual, nunca inferidos
    $sanitized['journalAbbrev'] = isset( $meta['journalAbbrev'] ) ? sanitize_text_field( $meta['journalAbbrev'] ) : '';
    $sanitized['pmid'] = isset( $meta['pmid'] ) ? sanitize_text_field( $meta['pmid'] ) : '';

    $authors = array();
    if ( isset( $meta['authors'] ) && is_array( $meta['authors'] ) ) {
        foreach ( $meta['authors'] as $auth ) {
            if ( isset( $auth['isCorporate'] ) && $auth['isCorporate'] ) {
                $authors[] = array(
                    'name'        => sanitize_text_field( $auth['name'] ),
                    'isCorporate' => true
                );
            } else {
                $authors[] = array(
                    'firstName'      => sanitize_text_field( $auth['firstName'] ),
                    'middleName'     => sanitize_text_field( $auth['middleName'] ),
                    'lastName'       => sanitize_text_field( $auth['lastName'] ),
                    'secondLastName' => sanitize_text_field( $auth['secondLastName'] ),
                    'isCorporate'    => false
                );
            }
        }
    }
    $sanitized['authors'] = $authors;
    return $sanitized;
}

// --------------------------------------------------------------------
// 3. UTILITIES & TRADUCCIONES (i18n)
// --------------------------------------------------------------------

/**
 * Diccionario de traducción multilenguaje (Español / Inglés) para términos académicos.
 */
function cgwp_t( $key, $lang = 'es' ) {
    $translations = array(
        'en' => array(
            'and' => '&',
            'in' => 'In',
            'ed' => 'Ed.',
            'eds' => 'Eds.',
            'retrievedFrom' => 'Retrieved from',
            'nd' => 'n.d.',
            'masterThesis' => "Master's thesis",
            'doctoralDissertation' => 'Doctoral dissertation'
        ),
        'es' => array(
            'and' => 'y',
            'in' => 'En',
            'ed' => 'Ed.',
            'eds' => 'Eds.',
            'retrievedFrom' => 'Recuperado de',
            'nd' => 's.f.',
            'masterThesis' => 'Tesis de maestría',
            'doctoralDissertation' => 'Disertación doctoral'
        )
    );
    $normalized_lang = strtolower( substr( $lang, 0, 2 ) );
    $lang_dict = isset( $translations[$normalized_lang] ) ? $translations[$normalized_lang] : $translations['en'];
    return isset( $lang_dict[$key] ) ? $lang_dict[$key] : $key;
}

/**
 * Formatea nombres de autores (personas o corporativos) en estilos como last-first o last-init.
 */
function cgwp_get_full_author_name( $author, $format = 'last-first' ) {
    if ( isset( $author['isCorporate'] ) && $author['isCorporate'] ) {
        return $author['name'];
    }

    $first = isset( $author['firstName'] ) ? trim( $author['firstName'] ) : '';
    $middle = isset( $author['middleName'] ) ? trim( $author['middleName'] ) : '';
    $last = isset( $author['lastName'] ) ? trim( $author['lastName'] ) : '';
    $second_last = isset( $author['secondLastName'] ) ? trim( $author['secondLastName'] ) : '';

    $full_first = trim( $first . ' ' . $middle );
    $full_last = trim( $last . ' ' . $second_last );

    if ( empty( $full_first ) ) return $full_last;
    if ( empty( $full_last ) ) return $full_first;

    if ( $format === 'last-first' ) {
        return $full_last . ', ' . $full_first;
    } elseif ( $format === 'first-last' ) {
        return $full_first . ' ' . $full_last;
    } elseif ( $format === 'last-init' ) {
        $initials = '';
        if ( ! empty( $first ) ) $initials .= mb_substr( $first, 0, 1, 'UTF-8' ) . '.';
        if ( ! empty( $middle ) ) $initials .= ' ' . mb_substr( $middle, 0, 1, 'UTF-8' ) . '.';
        $initials = trim( $initials );
        return $full_last . ', ' . $initials;
    } elseif ( $format === 'init-last' ) {
        $initials = '';
        if ( ! empty( $first ) ) $initials .= mb_substr( $first, 0, 1, 'UTF-8' ) . '.';
        if ( ! empty( $middle ) ) $initials .= ' ' . mb_substr( $middle, 0, 1, 'UTF-8' ) . '.';
        $initials = trim( $initials );
        return $initials . ' ' . $full_last;
    } elseif ( $format === 'last-only' ) {
        return $full_last;
    }

    return $full_last . ', ' . $full_first;
}

// --------------------------------------------------------------------
// 4. FORMATEADORES BIBLIOGRÁFICOS (APA, IEEE, Vancouver, Chicago, etc.)
// --------------------------------------------------------------------

/**
 * Genera cita en formato APA 6ª Edición.
 */
function cgwp_format_apa6( $metadata ) {
    $lang = isset( $metadata['lang'] ) ? $metadata['lang'] : 'es';
    $source = isset( $metadata['sourceType'] ) ? $metadata['sourceType'] : 'website';
    $conjunction = cgwp_t( 'and', $lang );

    $authors_list = array();
    if ( ! empty( $metadata['authors'] ) ) {
        foreach ( $metadata['authors'] as $auth ) {
            $authors_list[] = cgwp_get_full_author_name( $auth, 'last-init' );
        }
    }

    $authors_str = '';
    $count = count( $authors_list );
    if ( $count === 1 ) {
        $authors_str = $authors_list[0];
    } elseif ( $count >= 2 && $count <= 7 ) {
        $last = array_pop( $authors_list );
        $authors_str = implode( ', ', $authors_list ) . ', ' . $conjunction . ' ' . $last;
    } elseif ( $count > 7 ) {
        $authors_str = implode( ', ', array_slice( $authors_list, 0, 6 ) ) . ', ... ' . $authors_list[$count - 1];
    }

    $year = ! empty( $metadata['year'] ) ? $metadata['year'] : cgwp_t( 'nd', $lang );
    $date_part = '(' . $year . '). ';
    $author_part = ! empty( $authors_str ) ? $authors_str . ' ' : '';
    $title = isset( $metadata['title'] ) ? trim( $metadata['title'] ) : 'Untitled';
    $details = '';

    switch ( $source ) {
        case 'book':
            $ed = ! empty( $metadata['edition'] ) ? ' (' . $metadata['edition'] . ' ed.)' : '';
            $place = ! empty( $metadata['place'] ) ? $metadata['place'] : '';
            $pub = ! empty( $metadata['publisher'] ) ? $metadata['publisher'] : '';
            $pub_info = '';
            if ( $place && $pub ) $pub_info = ' ' . $place . ': ' . $pub . '.';
            elseif ( $pub ) $pub_info = ' ' . $pub . '.';
            $details = '<i>' . $title . '</i>' . $ed . '.' . $pub_info;
            break;

        case 'journal_article':
            $details = $title . '.';
            if ( ! empty( $metadata['journalName'] ) ) {
                $details .= ' <i>' . $metadata['journalName'] . '</i>';
                if ( ! empty( $metadata['volume'] ) ) {
                    $details .= ', <i>' . $metadata['volume'] . '</i>';
                    if ( ! empty( $metadata['issue'] ) ) {
                        $details .= '(' . $metadata['issue'] . ')';
                    }
                }
                if ( ! empty( $metadata['pages'] ) ) {
                    $details .= ', ' . $metadata['pages'];
                }
                $details .= '.';
            }
            break;

        default:
            $details = '<i>' . $title . '</i>.';
            $web = ! empty( $metadata['websiteName'] ) ? ' ' . $metadata['websiteName'] . '.' : '';
            $details .= $web;
            break;
    }

    $link_part = '';
    if ( ! empty( $metadata['doi'] ) ) {
        $link_part = ' https://doi.org/' . $metadata['doi'];
    } elseif ( ! empty( $metadata['url'] ) ) {
        $link_part = ' ' . cgwp_t( 'retrievedFrom', $lang ) . ' ' . $metadata['url'];
    }

    return trim( $author_part . $date_part . $details . $link_part );
}

// 2. APA 7 — CORREGIDO: elipsis espaciada, tipo "ai_generative"
function cgwp_format_apa7( $metadata ) {
    $lang = isset( $metadata['lang'] ) ? $metadata['lang'] : 'es';
    $source = isset( $metadata['sourceType'] ) ? $metadata['sourceType'] : 'website';
    $conjunction = cgwp_t( 'and', $lang );

    $authors_list = array();
    if ( ! empty( $metadata['authors'] ) ) {
        foreach ( $metadata['authors'] as $auth ) {
            $authors_list[] = cgwp_get_full_author_name( $auth, 'last-init' );
        }
    }

    $authors_str = '';
    $count = count( $authors_list );
    if ( $count === 1 ) {
        $authors_str = $authors_list[0];
    } elseif ( $count >= 2 && $count <= 20 ) {
        $last = array_pop( $authors_list );
        $authors_str = implode( ', ', $authors_list ) . ', ' . $conjunction . ' ' . $last;
    } elseif ( $count > 20 ) {
        // CORRECCIÓN: puntos suspensivos espaciados ". . ." (Publication Manual APA 7, no "...")
        $authors_str = implode( ', ', array_slice( $authors_list, 0, 19 ) ) . ', . . . ' . $authors_list[$count - 1];
    }

    $year = ! empty( $metadata['year'] ) ? $metadata['year'] : cgwp_t( 'nd', $lang );
    $date_part = '(' . $year . '). ';
    $author_part = ! empty( $authors_str ) ? $authors_str . ' ' : '';
    $title = isset( $metadata['title'] ) ? trim( $metadata['title'] ) : 'Untitled';
    $details = '';

    switch ( $source ) {
        case 'book':
            $ed = ! empty( $metadata['edition'] ) ? ' (' . $metadata['edition'] . ' ed.)' : '';
            $pub = ! empty( $metadata['publisher'] ) ? ' ' . $metadata['publisher'] . '.' : '';
            $details = '<i>' . $title . '</i>' . $ed . '.' . $pub;
            break;

        case 'journal_article':
            $details = $title . '.';
            if ( ! empty( $metadata['journalName'] ) ) {
                $details .= ' <i>' . $metadata['journalName'] . '</i>';
                if ( ! empty( $metadata['volume'] ) ) {
                    $details .= ', <i>' . $metadata['volume'] . '</i>';
                    if ( ! empty( $metadata['issue'] ) ) {
                        $details .= '(' . $metadata['issue'] . ')';
                    }
                }
                if ( ! empty( $metadata['pages'] ) ) {
                    $details .= ', ' . $metadata['pages'];
                }
                $details .= '.';
            }
            break;

        case 'chapter':
            $editors_str = '';
            if ( ! empty( $metadata['editors'] ) ) {
                $ed_list = array();
                foreach ( $metadata['editors'] as $ed ) {
                    $ed_list[] = cgwp_get_full_author_name( $ed, 'init-last' );
                }
                $ed_label = count( $ed_list ) > 1 ? cgwp_t( 'eds', $lang ) : cgwp_t( 'ed', $lang );
                $editors_str = ' ' . cgwp_t( 'in', $lang ) . ' ' . implode( ', ', $ed_list ) . ' (' . $ed_label . '),';
            } else {
                $editors_str = ' ' . cgwp_t( 'in', $lang );
            }
            $book = ! empty( $metadata['bookTitle'] ) ? ' <i>' . $metadata['bookTitle'] . '</i>' : '';
            $pages = ! empty( $metadata['pages'] ) ? ' (pp. ' . $metadata['pages'] . ')' : '';
            $pub = ! empty( $metadata['publisher'] ) ? '. ' . $metadata['publisher'] : '';
            $details = $title . '.' . $editors_str . $book . $pages . $pub . '.';
            break;

        case 'website':
            $site = ! empty( $metadata['websiteName'] ) ? ' ' . $metadata['websiteName'] . '.' : '';
            $details = '<i>' . $title . '</i>.' . $site;
            break;

        case 'thesis':
        case 'dissertation':
            $degree_label = ( $source === 'thesis' ) ? cgwp_t( 'masterThesis', $lang ) : cgwp_t( 'doctoralDissertation', $lang );
            $degree = ! empty( $metadata['degree'] ) ? $metadata['degree'] : $degree_label;
            $inst = ! empty( $metadata['institution'] ) ? ', ' . $metadata['institution'] : '';
            $details = '<i>' . $title . '</i> [' . $degree . $inst . '].';
            break;

        case 'report':
            $pub = ! empty( $metadata['publisher'] ) ? ' ' . $metadata['publisher'] . '.' : '';
            $details = '<i>' . $title . '</i>.' . $pub;
            break;

        case 'newspaper':
            $paper = ! empty( $metadata['journalName'] ) ? ' <i>' . $metadata['journalName'] . '</i>.' : '';
            $details = $title . '.' . $paper;
            break;

        case 'magazine':
            $details = $title . '.';
            if ( ! empty( $metadata['journalName'] ) ) {
                $details .= ' <i>' . $metadata['journalName'] . '</i>';
                if ( ! empty( $metadata['volume'] ) ) {
                    $details .= ', <i>' . $metadata['volume'] . '</i>';
                    if ( ! empty( $metadata['issue'] ) ) {
                        $details .= '(' . $metadata['issue'] . ')';
                    }
                }
                if ( ! empty( $metadata['pages'] ) ) {
                    $details .= ', ' . $metadata['pages'];
                }
                $details .= '.';
            }
            break;

        case 'conference_paper':
            $conf = ! empty( $metadata['conferenceName'] ) ? ' ' . $metadata['conferenceName'] : '';
            $place = ! empty( $metadata['place'] ) ? ', ' . $metadata['place'] : '';
            $details = '<i>' . $title . '</i> [Ponencia].' . $conf . $place . '.';
            break;

        // NUEVO: IA generativa. Autor = compañía (viaja como authors[0], isCorporate=true).
        // Formato APA vigente: Compañía. (Año). Nombre del modelo (versión) [Large language model]. URL
        case 'ai_generative':
            $version = ! empty( $metadata['aiVersion'] ) ? ' (' . $metadata['aiVersion'] . ')' : '';
            $details = '<i>' . $title . '</i>' . $version . ' [Large language model].';
            break;

        default:
            $details = '<i>' . $title . '</i>.';
            $web = ! empty( $metadata['websiteName'] ) ? ' ' . $metadata['websiteName'] . '.' : '';
            $details .= $web;
            break;
    }

    $link_part = '';
    // IA generativa no usa fecha de recuperación ni DOI: solo la URL de la herramienta si existe.
    if ( $source === 'ai_generative' ) {
        $link_part = ! empty( $metadata['url'] ) ? ' ' . $metadata['url'] : '';
    } elseif ( ! empty( $metadata['doi'] ) ) {
        $link_part = ' https://doi.org/' . $metadata['doi'];
    } elseif ( ! empty( $metadata['url'] ) ) {
        $link_part = ' ' . $metadata['url'];
    }

    return trim( $author_part . $date_part . $details . $link_part );
}

// 2b. NUEVO — APA 7: cita narrativa "Autor (Año)"
function cgwp_format_apa7_narrative( $metadata ) {
    $lang = isset( $metadata['lang'] ) ? $metadata['lang'] : 'es';
    $year = ! empty( $metadata['year'] ) ? $metadata['year'] : cgwp_t( 'nd', $lang );
    $conjunction = cgwp_t( 'and', $lang );

    if ( empty( $metadata['authors'] ) ) {
        $words = explode( ' ', trim( isset( $metadata['title'] ) ? $metadata['title'] : 'Untitled' ) );
        $short = implode( ' ', array_slice( $words, 0, 3 ) );
        return '"' . $short . '..." (' . $year . ')';
    }

    $authors = $metadata['authors'];
    $count = count( $authors );
    $first_last = ( isset( $authors[0]['isCorporate'] ) && $authors[0]['isCorporate'] )
        ? $authors[0]['name']
        : ( isset( $authors[0]['lastName'] ) ? $authors[0]['lastName'] : '' );

    if ( $count === 1 ) {
        $who = $first_last;
    } elseif ( $count === 2 ) {
        $second_last = ( isset( $authors[1]['isCorporate'] ) && $authors[1]['isCorporate'] )
            ? $authors[1]['name']
            : ( isset( $authors[1]['lastName'] ) ? $authors[1]['lastName'] : '' );
        $who = $first_last . ' ' . $conjunction . ' ' . $second_last;
    } else {
        // APA7: a partir de 3 autores se usa "et al." desde la PRIMERA cita (cambio respecto a APA6)
        $who = $first_last . ' et al.';
    }

    return $who . ' (' . $year . ')';
}

// 2c. NUEVO — APA 7: cita parentética "(Autor, Año)". El conector es SIEMPRE "&", no varía por idioma.
function cgwp_format_apa7_parenthetical( $metadata ) {
    $lang = isset( $metadata['lang'] ) ? $metadata['lang'] : 'es';
    $year = ! empty( $metadata['year'] ) ? $metadata['year'] : cgwp_t( 'nd', $lang );

    if ( empty( $metadata['authors'] ) ) {
        $words = explode( ' ', trim( isset( $metadata['title'] ) ? $metadata['title'] : 'Untitled' ) );
        $short = implode( ' ', array_slice( $words, 0, 3 ) );
        return '("' . $short . '...", ' . $year . ')';
    }

    $authors = $metadata['authors'];
    $count = count( $authors );
    $first_last = ( isset( $authors[0]['isCorporate'] ) && $authors[0]['isCorporate'] )
        ? $authors[0]['name']
        : ( isset( $authors[0]['lastName'] ) ? $authors[0]['lastName'] : '' );

    if ( $count === 1 ) {
        $who = $first_last;
    } elseif ( $count === 2 ) {
        $second_last = ( isset( $authors[1]['isCorporate'] ) && $authors[1]['isCorporate'] )
            ? $authors[1]['name']
            : ( isset( $authors[1]['lastName'] ) ? $authors[1]['lastName'] : '' );
        $who = $first_last . ' & ' . $second_last;
    } else {
        $who = $first_last . ' et al.';
    }

    return '(' . $who . ', ' . $year . ')';
}

// 3. Sistema Harvard (sin cambios; fuera del alcance solicitado)
function cgwp_format_harvard( $metadata ) {
    $authors_list = array();
    if ( ! empty( $metadata['authors'] ) ) {
        foreach ( $metadata['authors'] as $auth ) {
            $authors_list[] = cgwp_get_full_author_name( $auth, 'last-init' );
        }
    }
    $authors = ! empty( $authors_list ) ? implode( ', ', $authors_list ) : 'Anon.';
    $year = ! empty( $metadata['year'] ) ? $metadata['year'] : 'n.d.';
    $title = isset( $metadata['title'] ) ? trim( $metadata['title'] ) : 'Untitled';
    $source = isset( $metadata['sourceType'] ) ? $metadata['sourceType'] : 'website';

    $details = '';
    switch ( $source ) {
        case 'book':
            $details = '<i>' . $title . '</i>';
            if ( ! empty( $metadata['publisher'] ) ) $details .= ', ' . $metadata['publisher'];
            if ( ! empty( $metadata['place'] ) ) $details .= ', ' . $metadata['place'];
            $details .= '.';
            break;
        case 'journal_article':
            $details = "'" . $title . "'";
            if ( ! empty( $metadata['journalName'] ) ) $details .= ', <i>' . $metadata['journalName'] . '</i>';
            if ( ! empty( $metadata['volume'] ) ) $details .= ', vol. ' . $metadata['volume'];
            if ( ! empty( $metadata['issue'] ) ) $details .= ', no. ' . $metadata['issue'];
            if ( ! empty( $metadata['pages'] ) ) $details .= ', pp. ' . $metadata['pages'];
            $details .= '.';
            break;
        default:
            $details = '<i>' . $title . '</i>';
            if ( ! empty( $metadata['websiteName'] ) ) $details .= ', <i>' . $metadata['websiteName'] . '</i>';
            if ( ! empty( $metadata['url'] ) ) $details .= ', &lt;' . $metadata['url'] . '&gt;';
            $details .= '.';
            break;
    }

    return trim( $authors . ', ' . $year . '. ' . $details );
}

// --------------------------------------------------------------------
// CHICAGO 18 — Sistema Notes & Bibliography + Author-Date (ambos obligatorios)
// --------------------------------------------------------------------

// Autores para Notes&Bibliography. $invert_first=true -> para bibliografía (primer autor invertido).
// Trunca a "Primero et al." solo con 10+ autores (regla Chicago 18 para N&B).
function cgwp_chicago_note_authors( $metadata, $invert_first = false ) {
    if ( empty( $metadata['authors'] ) ) return 'Anonymous';
    $list = $metadata['authors'];
    $count = count( $list );
    $first = cgwp_get_full_author_name( $list[0], $invert_first ? 'last-first' : 'first-last' );
    if ( $count === 1 ) return $first;
    if ( $count >= 10 ) {
        return $first . ' et al.';
    }
    $rest = array();
    for ( $i = 1; $i < $count; $i++ ) {
        $rest[] = cgwp_get_full_author_name( $list[$i], 'first-last' );
    }
    return $first . ', and ' . implode( ', ', $rest );
}

// 4a. Chicago — BIBLIOGRAFÍA (autor invertido: Apellido, Nombre)
function cgwp_format_chicago_bibliography( $metadata ) {
    $authors = '';
    if ( ! empty( $metadata['authors'] ) ) {
        $count = count( $metadata['authors'] );
        if ( $count === 1 ) {
            $authors = cgwp_get_full_author_name( $metadata['authors'][0], 'last-first' );
        } elseif ( $count === 2 ) {
            $first = cgwp_get_full_author_name( $metadata['authors'][0], 'last-first' );
            $second = cgwp_get_full_author_name( $metadata['authors'][1], 'first-last' );
            $authors = $first . ', and ' . $second;
        } elseif ( $count >= 3 && $count <= 9 ) {
            $first = cgwp_get_full_author_name( $metadata['authors'][0], 'last-first' );
            $rest = array();
            for ( $i = 1; $i < $count - 1; $i++ ) {
                $rest[] = cgwp_get_full_author_name( $metadata['authors'][$i], 'first-last' );
            }
            $last = cgwp_get_full_author_name( $metadata['authors'][$count - 1], 'first-last' );
            $authors = $first . ', ' . ( ! empty( $rest ) ? implode( ', ', $rest ) . ', ' : '' ) . 'and ' . $last;
        } else {
            // 10+ autores: primero + "et al." (Chicago 18, N&B)
            $first = cgwp_get_full_author_name( $metadata['authors'][0], 'last-first' );
            $authors = $first . ', et al.';
        }
    } else {
        $authors = 'Anonymous';
    }

    $title = isset( $metadata['title'] ) ? trim( $metadata['title'] ) : 'Untitled';
    $year = ! empty( $metadata['year'] ) ? $metadata['year'] : '';
    $source = isset( $metadata['sourceType'] ) ? $metadata['sourceType'] : 'website';

    $details = '';
    switch ( $source ) {
        case 'book':
            $details = '<i>' . $title . '</i>';
            if ( ! empty( $metadata['publisher'] ) ) {
                $details .= '. ' . $metadata['publisher'];
            }
            if ( $year ) $details .= ', ' . $year;
            $details .= '.';
            break;

        case 'journal_article':
            $details = '"' . $title . '."';
            if ( ! empty( $metadata['journalName'] ) ) $details .= ' <i>' . $metadata['journalName'] . '</i>';
            if ( ! empty( $metadata['volume'] ) ) $details .= ' ' . $metadata['volume'];
            if ( ! empty( $metadata['issue'] ) ) $details .= ', no. ' . $metadata['issue'];
            if ( $year ) $details .= ' (' . $year . ')';
            if ( ! empty( $metadata['pages'] ) ) $details .= ': ' . $metadata['pages'];
            $details .= '.';
            break;

        case 'chapter':
            $details = '"' . $title . '."';
            $book = ! empty( $metadata['bookTitle'] ) ? ' In <i>' . $metadata['bookTitle'] . '</i>' : '';

            $eds_str = '';
            if ( ! empty( $metadata['editors'] ) ) {
                $eds_list = array();
                foreach ( $metadata['editors'] as $ed ) {
                    $eds_list[] = cgwp_get_full_author_name( $ed, 'first-last' );
                }
                $eds_str = ', edited by ' . implode( ' and ', $eds_list );
            }

            $pages = ! empty( $metadata['pages'] ) ? ', ' . $metadata['pages'] : '';
            $pub = ! empty( $metadata['publisher'] ) ? '. ' . $metadata['publisher'] : '';

            $details .= $book . $eds_str . $pages . $pub;
            if ( $year ) $details .= ', ' . $year;
            $details .= '.';
            break;

        case 'thesis':
        case 'dissertation':
            $degree = ( $source === 'thesis' ) ? "Master's thesis" : "PhD diss.";
            $inst = ! empty( $metadata['institution'] ) ? ', ' . $metadata['institution'] : '';
            $details = '"' . $title . '."' . ' ' . $degree . $inst;
            if ( $year ) $details .= ', ' . $year;
            $details .= '.';
            break;

        case 'report':
            $pub = ! empty( $metadata['publisher'] ) ? '. ' . $metadata['publisher'] : '';
            $details = '<i>' . $title . '</i>' . $pub;
            if ( $year ) $details .= ', ' . $year;
            $details .= '.';
            break;

        case 'newspaper':
        case 'magazine':
            $paper = ! empty( $metadata['journalName'] ) ? ' <i>' . $metadata['journalName'] . '</i>' : '';
            $vol_part = '';
            if ( ! empty( $metadata['volume'] ) ) {
                $vol_part .= ' ' . $metadata['volume'];
                if ( ! empty( $metadata['issue'] ) ) {
                    $vol_part .= ', no. ' . $metadata['issue'];
                }
            }
            $pages = ! empty( $metadata['pages'] ) ? ', ' . $metadata['pages'] : '';

            $details = '"' . $title . '."';
            if ( $paper ) $details .= $paper;
            if ( $vol_part ) $details .= $vol_part;
            if ( $year ) $details .= ', ' . $year;
            if ( $pages ) $details .= $pages;
            $details .= '.';
            break;

        case 'conference_paper':
            $conf = ! empty( $metadata['conferenceName'] ) ? ' Paper presented at ' . $metadata['conferenceName'] : '';
            $place = ! empty( $metadata['place'] ) ? ', ' . $metadata['place'] : '';
            $details = '"' . $title . '."' . $conf . $place;
            if ( $year ) $details .= ', ' . $year;
            $details .= '.';
            break;

        default:
            $details = '"' . $title . '."';
            if ( ! empty( $metadata['websiteName'] ) ) $details .= ' ' . $metadata['websiteName'] . '.';
            break;
    }

    $link_part = '';
    if ( ! empty( $metadata['doi'] ) ) {
        $link_part = ' https://doi.org/' . $metadata['doi'];
    } elseif ( ! empty( $metadata['url'] ) ) {
        $link_part = ' ' . $metadata['url'];
    }

    return trim( $authors . '. ' . $details . $link_part );
}

// Alias por compatibilidad con el frontend/version anterior (misma salida que bibliography)
function cgwp_format_chicago( $metadata ) {
    return cgwp_format_chicago_bibliography( $metadata );
}

// 4b. NUEVO — Chicago N&B: NOTA COMPLETA (primera cita; autor en orden natural, no invertido)
function cgwp_format_chicago_note_full( $metadata ) {
    $authors = cgwp_chicago_note_authors( $metadata, false );
    $title   = isset( $metadata['title'] ) ? trim( $metadata['title'] ) : 'Untitled';
    $source  = isset( $metadata['sourceType'] ) ? $metadata['sourceType'] : 'website';
    $year    = ! empty( $metadata['year'] ) ? $metadata['year'] : 'n.d.';

    switch ( $source ) {
        case 'book':
            $pub = ( ! empty( $metadata['place'] ) && ! empty( $metadata['publisher'] ) )
                ? $metadata['place'] . ': ' . $metadata['publisher']
                : ( ! empty( $metadata['publisher'] ) ? $metadata['publisher'] : '' );
            $body = '<i>' . $title . '</i> (' . $pub . ( $pub ? ', ' : '' ) . $year . ')';
            break;

        case 'journal_article':
            $body = '"' . $title . '," <i>' . ( ! empty( $metadata['journalName'] ) ? $metadata['journalName'] : '' ) . '</i> '
                . ( ! empty( $metadata['volume'] ) ? $metadata['volume'] : '' )
                . ( ! empty( $metadata['issue'] ) ? ', no. ' . $metadata['issue'] : '' )
                . ' (' . $year . ')'
                . ( ! empty( $metadata['pages'] ) ? ': ' . $metadata['pages'] : '' );
            break;

        case 'chapter':
            $book = ! empty( $metadata['bookTitle'] ) ? ' in <i>' . $metadata['bookTitle'] . '</i>' : '';
            $pages = ! empty( $metadata['pages'] ) ? ', ' . $metadata['pages'] : '';
            $body = '"' . $title . ',"' . $book . ( $year ? ' (' . $year . ')' : '' ) . $pages;
            break;

        default:
            $site = ! empty( $metadata['websiteName'] ) ? $metadata['websiteName'] . ', ' : '';
            $body = '"' . $title . '," ' . $site . 'accessed ' . date( 'F j, Y' );
            break;
    }

    $link = ! empty( $metadata['doi'] )
        ? ', https://doi.org/' . $metadata['doi']
        : ( ! empty( $metadata['url'] ) ? ', ' . $metadata['url'] : '' );

    return trim( $authors . ', ' . $body . $link . '.' );
}

// 4c. NUEVO — Chicago N&B: NOTA ABREVIADA (para citas repetidas de la misma fuente)
function cgwp_format_chicago_note_short( $metadata ) {
    $last_name = 'Anonymous';
    if ( ! empty( $metadata['authors'][0] ) ) {
        $a = $metadata['authors'][0];
        $last_name = ( isset( $a['isCorporate'] ) && $a['isCorporate'] ) ? $a['name'] : ( isset( $a['lastName'] ) ? $a['lastName'] : '' );
    }
    $title_words = explode( ' ', trim( isset( $metadata['title'] ) ? $metadata['title'] : 'Untitled' ) );
    $short_title = implode( ' ', array_slice( $title_words, 0, 4 ) );
    $pages = ! empty( $metadata['pages'] ) ? ', ' . $metadata['pages'] : '';

    // CORRECCIÓN: la elipsis solo se agrega si el título realmente se truncó (más de 4 palabras).
    // Chicago 18 no permite "..." sobre un título corto que ya se citó completo.
    $was_truncated = count( $title_words ) > 4;
    $title_part = $was_truncated ? $short_title . '...' : $short_title;

    return $last_name . ', "' . $title_part . ',"' . $pages . '.';
}

// 4d. NUEVO — Chicago Author-Date: CITA EN TEXTO "(Autor Año)"
function cgwp_format_chicago_authordate_intext( $metadata ) {
    $last_name = 'Anonymous';
    if ( ! empty( $metadata['authors'][0] ) ) {
        $a = $metadata['authors'][0];
        $last_name = ( isset( $a['isCorporate'] ) && $a['isCorporate'] ) ? $a['name'] : ( isset( $a['lastName'] ) ? $a['lastName'] : '' );
    }
    $count = ! empty( $metadata['authors'] ) ? count( $metadata['authors'] ) : 0;

    if ( $count === 2 && ! empty( $metadata['authors'][1]['lastName'] ) ) {
        $last_name .= ' and ' . $metadata['authors'][1]['lastName'];
    } elseif ( $count === 3 && ! empty( $metadata['authors'][1]['lastName'] ) && ! empty( $metadata['authors'][2]['lastName'] ) ) {
        $last_name .= ', ' . $metadata['authors'][1]['lastName'] . ', and ' . $metadata['authors'][2]['lastName'];
    } elseif ( $count > 3 ) {
        $last_name .= ' et al.';
    }

    $year = ! empty( $metadata['year'] ) ? $metadata['year'] : 'n.d.';
    return '(' . $last_name . ' ' . $year . ')';
}

// 4e. NUEVO — Chicago Author-Date: REFERENCIA (el año va inmediatamente después del autor, no al final)
function cgwp_format_chicago_authordate_reference( $metadata ) {
    $authors = cgwp_chicago_note_authors( $metadata, true );
    $year    = ! empty( $metadata['year'] ) ? $metadata['year'] : 'n.d.';
    $title   = isset( $metadata['title'] ) ? trim( $metadata['title'] ) : 'Untitled';
    $source  = isset( $metadata['sourceType'] ) ? $metadata['sourceType'] : 'website';

    switch ( $source ) {
        case 'book':
            $pub = ! empty( $metadata['publisher'] ) ? $metadata['publisher'] . '.' : '';
            $details = '<i>' . $title . '</i>. ' . $pub;
            break;
        case 'journal_article':
            $details = '"' . $title . '." <i>' . ( ! empty( $metadata['journalName'] ) ? $metadata['journalName'] : '' ) . '</i> '
                . ( ! empty( $metadata['volume'] ) ? $metadata['volume'] : '' )
                . ( ! empty( $metadata['issue'] ) ? ', no. ' . $metadata['issue'] : '' )
                . ( ! empty( $metadata['pages'] ) ? ': ' . $metadata['pages'] : '' ) . '.';
            break;
        case 'chapter':
            $book = ! empty( $metadata['bookTitle'] ) ? ' In <i>' . $metadata['bookTitle'] . '</i>' : '';
            $pages = ! empty( $metadata['pages'] ) ? ', ' . $metadata['pages'] : '';
            $details = '"' . $title . '."' . $book . $pages . '.';
            break;
        default:
            $details = '"' . $title . '." ' . ( ! empty( $metadata['websiteName'] ) ? $metadata['websiteName'] : '' ) . '.';
            break;
    }

    $link = ! empty( $metadata['doi'] )
        ? ' https://doi.org/' . $metadata['doi']
        : ( ! empty( $metadata['url'] ) ? ' ' . $metadata['url'] : '' );

    return trim( $authors . '. ' . $year . '. ' . $details . $link );
}

// 5. Turabian (idéntico en estructura a Chicago N&B bibliografía; fuera de alcance)
function cgwp_format_turabian( $metadata ) {
    return cgwp_format_chicago_bibliography( $metadata );
}

// 6. IEEE — CORREGIDO: truncamiento "Primero et al." con 7+ autores
function cgwp_format_ieee( $metadata ) {
    $authors_list = array();
    if ( ! empty( $metadata['authors'] ) ) {
        foreach ( $metadata['authors'] as $auth ) {
            $authors_list[] = cgwp_get_full_author_name( $auth, 'init-last' );
        }
    }
    $authors_str = '';
    $count = count( $authors_list );
    if ( $count === 1 ) {
        $authors_str = $authors_list[0];
    } elseif ( $count === 2 ) {
        $authors_str = $authors_list[0] . ' and ' . $authors_list[1];
    } elseif ( $count >= 3 && $count <= 6 ) {
        $last = array_pop( $authors_list );
        $authors_str = implode( ', ', $authors_list ) . ', and ' . $last;
    } elseif ( $count > 6 ) {
        // CORRECCIÓN: IEEE Reference Guide vigente exige "Primero et al." con 7+ autores en la referencia
        $authors_str = $authors_list[0] . ' <i>et al.</i>';
    } else {
        $authors_str = 'Anonymous';
    }

    $title = isset( $metadata['title'] ) ? trim( $metadata['title'] ) : 'Untitled';
    $source = isset( $metadata['sourceType'] ) ? $metadata['sourceType'] : 'website';
    $year = ! empty( $metadata['year'] ) ? $metadata['year'] : '';

    $output = $authors_str . ', ';

    switch ( $source ) {
        case 'book':
            $output .= '<i>' . $title . '</i>';
            $place = ! empty( $metadata['place'] ) ? $metadata['place'] : '';
            $pub = ! empty( $metadata['publisher'] ) ? $metadata['publisher'] : '';
            if ( $place && $pub ) {
                $output .= '. ' . $place . ': ' . $pub;
            } elseif ( $pub ) {
                $output .= '. ' . $pub;
            }
            if ( $year ) $output .= ', ' . $year;
            $output .= '.';
            break;

        case 'journal_article':
            $output .= '"' . rtrim( $title, '.' ) . ',"';
            if ( ! empty( $metadata['journalName'] ) ) $output .= ' <i>' . $metadata['journalName'] . '</i>';
            if ( ! empty( $metadata['volume'] ) ) $output .= ', vol. ' . $metadata['volume'];
            if ( ! empty( $metadata['issue'] ) ) $output .= ', no. ' . $metadata['issue'];
            if ( ! empty( $metadata['pages'] ) ) $output .= ', pp. ' . $metadata['pages'];
            if ( $year ) $output .= ', ' . $year;
            $output .= '.';
            break;

        case 'chapter':
            $output .= '"' . rtrim( $title, '.' ) . ',"';
            $book = ! empty( $metadata['bookTitle'] ) ? ' in <i>' . $metadata['bookTitle'] . '</i>' : '';

            $eds_str = '';
            if ( ! empty( $metadata['editors'] ) ) {
                $eds_list = array();
                foreach ( $metadata['editors'] as $ed ) {
                    $eds_list[] = cgwp_get_full_author_name( $ed, 'init-last' );
                }
                $eds_label = count( $eds_list ) > 1 ? 'Eds.' : 'Ed.';
                $eds_str = ', ' . implode( ' and ', $eds_list ) . ', ' . $eds_label;
            }

            $place = ! empty( $metadata['place'] ) ? $metadata['place'] : '';
            $pub = ! empty( $metadata['publisher'] ) ? $metadata['publisher'] : '';
            $pub_info = '';
            if ( $place && $pub ) {
                $pub_info = '. ' . $place . ': ' . $pub;
            } elseif ( $pub ) {
                $pub_info = '. ' . $pub;
            }

            $pages = ! empty( $metadata['pages'] ) ? ', pp. ' . $metadata['pages'] : '';

            $output .= $book . $eds_str . $pub_info;
            if ( $year ) $output .= ', ' . $year;
            $output .= $pages . '.';
            break;

        case 'website':
            $output .= '"' . rtrim( $title, '.' ) . ',"';
            if ( $year ) $output .= ' ' . $year . '.';

            $output .= ' [Online]. Available: ' . ( ! empty( $metadata['url'] ) ? $metadata['url'] : '' );

            $accessed_date = date('M. d, Y');
            $output .= '. [Accessed: ' . $accessed_date . '].';
            break;

        case 'thesis':
        case 'dissertation':
            $output .= '"' . rtrim( $title, '.' ) . ',"';
            $degree = ! empty( $metadata['degree'] ) ? $metadata['degree'] : ( ( $source === 'thesis' ) ? 'M. S. thesis' : 'Ph. D. dissertation' );
            $inst = ! empty( $metadata['institution'] ) ? $metadata['institution'] : '';
            $place = ! empty( $metadata['place'] ) ? $metadata['place'] : '';

            $output .= ' ' . $degree;
            if ( $inst ) $output .= ', ' . $inst;
            if ( $place ) $output .= ', ' . $place;
            if ( $year ) $output .= ', ' . $year;
            $output .= '.';
            break;

        case 'report':
            $output .= '"' . rtrim( $title, '.' ) . ',"';
            $pub = ! empty( $metadata['publisher'] ) ? $metadata['publisher'] : '';
            $place = ! empty( $metadata['place'] ) ? $metadata['place'] : '';
            if ( $pub ) $output .= ' ' . $pub;
            if ( $place ) $output .= ', ' . $place;
            $output .= ', Tech. Report';
            if ( $year ) $output .= ', ' . $year;
            $output .= '.';
            break;

        case 'newspaper':
        case 'magazine':
            $output .= '"' . rtrim( $title, '.' ) . ',"';
            if ( ! empty( $metadata['journalName'] ) ) $output .= ' <i>' . $metadata['journalName'] . '</i>';
            if ( ! empty( $metadata['volume'] ) ) $output .= ', vol. ' . $metadata['volume'];
            if ( ! empty( $metadata['issue'] ) ) $output .= ', no. ' . $metadata['issue'];
            if ( ! empty( $metadata['pages'] ) ) $output .= ', pp. ' . $metadata['pages'];
            if ( $year ) $output .= ', ' . $year;
            $output .= '.';
            break;

        case 'conference_paper':
            $output .= '"' . rtrim( $title, '.' ) . ',"';
            $conf = ! empty( $metadata['conferenceName'] ) ? $metadata['conferenceName'] : '';
            $place = ! empty( $metadata['place'] ) ? $metadata['place'] : '';

            $output .= ' presented at ' . $conf;
            if ( $place ) $output .= ', ' . $place;
            if ( $year ) $output .= ', ' . $year;
            $output .= '.';
            break;

        default:
            $output .= '"' . rtrim( $title, '.' ) . '."';
            if ( ! empty( $metadata['websiteName'] ) ) $output .= ' ' . $metadata['websiteName'] . '.';
            if ( ! empty( $metadata['url'] ) ) $output .= ' [Online]. Available: ' . $metadata['url'];
            if ( $year ) $output .= ', ' . $year;
            $output .= '.';
            break;
    }

    return '[1] ' . trim( $output );
}

// 7. Vancouver — CORREGIDO: abreviatura NLM y PMID (siempre de entrada manual, nunca inferidos)
function cgwp_format_vancouver( $metadata ) {
    $lang = isset( $metadata['lang'] ) ? $metadata['lang'] : 'es';
    $source = isset( $metadata['sourceType'] ) ? $metadata['sourceType'] : 'website';

    $authors_list = array();
    if ( ! empty( $metadata['authors'] ) ) {
        foreach ( $metadata['authors'] as $auth ) {
            if ( isset( $auth['isCorporate'] ) && $auth['isCorporate'] ) {
                $authors_list[] = $auth['name'];
            } else {
                $first = isset( $auth['firstName'] ) ? trim( $auth['firstName'] ) : '';
                $middle = isset( $auth['middleName'] ) ? trim( $auth['middleName'] ) : '';
                $initials = '';
                if ( ! empty( $first ) ) $initials .= mb_substr( $first, 0, 1, 'UTF-8' );
                if ( ! empty( $middle ) ) $initials .= mb_substr( $middle, 0, 1, 'UTF-8' );

                $lastName = isset( $auth['lastName'] ) ? trim( $auth['lastName'] ) : '';
                $secondLast = isset( $auth['secondLastName'] ) ? trim( $auth['secondLastName'] ) : '';
                if ( ! empty( $secondLast ) ) {
                    $author_name = $lastName . '-' . $secondLast;
                } else {
                    $author_name = $lastName;
                }

                $authors_list[] = trim( $author_name . ' ' . $initials );
            }
        }
    }

    $authors_str = '';
    $count = count( $authors_list );
    if ( $count > 6 ) {
        $authors_str = implode( ', ', array_slice( $authors_list, 0, 6 ) ) . ', et al.';
    } else {
        $authors_str = implode( ', ', $authors_list );
    }

    $author_part = ! empty( $authors_str ) ? $authors_str . '. ' : '';
    $title = isset( $metadata['title'] ) ? trim( $metadata['title'] ) : 'Untitled';
    $year = ! empty( $metadata['year'] ) ? $metadata['year'] : '';

    $months_es = array(
        1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
        5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
        9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre'
    );
    $day = date( 'j' );
    $month_name = $months_es[(int)date( 'n' )];
    $year_now = date( 'Y' );
    $accessed_date = "$day de $month_name de $year_now";

    $details = '';
    switch ( $source ) {
        case 'book':
            $ed = ! empty( $metadata['edition'] ) ? ' ' . $metadata['edition'] . ' ed.' : '';
            $place = ! empty( $metadata['place'] ) ? $metadata['place'] : '';
            $pub = ! empty( $metadata['publisher'] ) ? $metadata['publisher'] : '';

            $pub_part = '';
            if ( $place && $pub ) {
                $pub_part = ' ' . $place . ': ' . $pub . ';';
            } elseif ( $pub ) {
                $pub_part = ' ' . $pub . ';';
            }

            $details = $title . '.' . $ed . $pub_part;
            if ( $year ) $details .= ' ' . $year;
            $details .= '.';
            break;

        case 'journal_article':
            // CORRECCIÓN: usar abreviatura NLM si el usuario la ingresó; si no, se cae al nombre completo
            // (esto NO es conforme a ICMJE hasta que el usuario complete el campo — el sistema nunca inventa la abreviatura).
            $journal_display = ! empty( $metadata['journalAbbrev'] ) ? $metadata['journalAbbrev'] : ( ! empty( $metadata['journalName'] ) ? $metadata['journalName'] : '' );
            $journal = ! empty( $journal_display ) ? ' ' . $journal_display . '.' : '';
            $details = $title . '.' . $journal;

            $date_part = $year ? ' ' . $year : '';
            $vol_part = '';
            if ( ! empty( $metadata['volume'] ) ) {
                $vol_part .= ';' . $metadata['volume'];
                if ( ! empty( $metadata['issue'] ) ) {
                    $vol_part .= '(' . $metadata['issue'] . ')';
                }
            }
            $pages_part = ! empty( $metadata['pages'] ) ? ':' . $metadata['pages'] : '';
            $pmid_part = ! empty( $metadata['pmid'] ) ? ' PMID: ' . $metadata['pmid'] . '.' : '';

            $details .= $date_part . $vol_part . $pages_part . '.' . $pmid_part;
            break;

        case 'chapter':
            $eds_str = '';
            if ( ! empty( $metadata['editors'] ) ) {
                $eds_list = array();
                foreach ( $metadata['editors'] as $ed ) {
                    if ( isset( $ed['isCorporate'] ) && $ed['isCorporate'] ) {
                        $eds_list[] = $ed['name'];
                    } else {
                        $first = isset( $ed['firstName'] ) ? trim( $ed['firstName'] ) : '';
                        $middle = isset( $ed['middleName'] ) ? trim( $ed['middleName'] ) : '';
                        $initials = '';
                        if ( ! empty( $first ) ) $initials .= mb_substr( $first, 0, 1, 'UTF-8' );
                        if ( ! empty( $middle ) ) $initials .= mb_substr( $middle, 0, 1, 'UTF-8' );

                        $lastName = isset( $ed['lastName'] ) ? trim( $ed['lastName'] ) : '';
                        $secondLast = isset( $ed['secondLastName'] ) ? trim( $ed['secondLastName'] ) : '';
                        if ( ! empty( $secondLast ) ) {
                            $author_name = $lastName . '-' . $secondLast;
                        } else {
                            $author_name = $lastName;
                        }
                        $eds_list[] = trim( $author_name . ' ' . $initials );
                    }
                }
                $eds_label = count( $eds_list ) > 1 ? 'editores' : 'editor';
                $eds_str = ' En: ' . implode( ', ', $eds_list ) . ', ' . $eds_label . '.';
            } else {
                $eds_str = ' En:';
            }

            $book = ! empty( $metadata['bookTitle'] ) ? ' ' . $metadata['bookTitle'] . '.' : '';
            $ed = ! empty( $metadata['edition'] ) ? ' ' . $metadata['edition'] . ' ed.' : '';

            $place = ! empty( $metadata['place'] ) ? $metadata['place'] : '';
            $pub = ! empty( $metadata['publisher'] ) ? $metadata['publisher'] : '';
            $pub_part = '';
            if ( $place && $pub ) {
                $pub_part = ' ' . $place . ': ' . $pub . ';';
            } elseif ( $pub ) {
                $pub_part = ' ' . $pub . ';';
            }

            $pages = ! empty( $metadata['pages'] ) ? ' p. ' . $metadata['pages'] . '.' : '.';

            $details = $title . '.' . $eds_str . $book . $ed . $pub_part;
            if ( $year ) $details .= ' ' . $year . '.';
            $details .= $pages;
            break;

        case 'website':
            $details = $title . ' [Internet].';
            if ( $year ) $details .= ' ' . $year;
            $details .= ' [citado ' . $accessed_date . '].';
            if ( ! empty( $metadata['url'] ) ) {
                $details .= ' Disponible en: ' . $metadata['url'];
            }
            break;

        case 'thesis':
        case 'dissertation':
            $degree_label = ( $source === 'thesis' ) ? 'Trabajo de grado' : 'Tesis doctoral';
            $degree = ! empty( $metadata['degree'] ) ? $metadata['degree'] : $degree_label;

            $place = ! empty( $metadata['place'] ) ? $metadata['place'] : '';
            $inst = ! empty( $metadata['institution'] ) ? $metadata['institution'] : '';
            $inst_part = '';
            if ( $place && $inst ) {
                $inst_part = ' ' . $place . ': ' . $inst . ';';
            } elseif ( $inst ) {
                $inst_part = ' ' . $inst . ';';
            }

            $details = $title . ' [Internet] [' . $degree . '].' . $inst_part;
            if ( $year ) $details .= ' ' . $year . '.';
            if ( ! empty( $metadata['url'] ) ) {
                $details .= ' Disponible en: ' . $metadata['url'];
            }
            break;

        case 'report':
            $place = ! empty( $metadata['place'] ) ? $metadata['place'] : '';
            $inst = ! empty( $metadata['institution'] ) ? $metadata['institution'] : ( ! empty( $metadata['publisher'] ) ? $metadata['publisher'] : '' );
            $inst_part = '';
            if ( $place && $inst ) {
                $inst_part = ' ' . $place . ': ' . $inst . ';';
            } elseif ( $inst ) {
                $inst_part = ' ' . $inst . ';';
            }
            $details = $title . ' [Internet].' . $inst_part;
            if ( $year ) $details .= ' ' . $year . '.';
            if ( ! empty( $metadata['url'] ) ) {
                $details .= ' Disponible en: ' . $metadata['url'];
            }
            break;

        case 'newspaper':
        case 'magazine':
            $journal_display = ! empty( $metadata['journalAbbrev'] ) ? $metadata['journalAbbrev'] : ( ! empty( $metadata['journalName'] ) ? $metadata['journalName'] : '' );
            $journal = ! empty( $journal_display ) ? ' ' . $journal_display : '';
            $details = $title . '.' . $journal . ' [Internet].';

            $date_part = $year ? ' ' . $year : '';
            $details .= $date_part . ' [citado ' . $accessed_date . '];';

            $vol_part = '';
            if ( ! empty( $metadata['volume'] ) ) {
                $vol_part .= $metadata['volume'];
                if ( ! empty( $metadata['issue'] ) ) {
                    $vol_part .= '(' . $metadata['issue'] . ')';
                }
            }
            $pages_part = ! empty( $metadata['pages'] ) ? ':' . $metadata['pages'] : '';

            $details .= $vol_part . $pages_part . '.';
            if ( ! empty( $metadata['url'] ) ) {
                $details .= ' Disponible en: ' . $metadata['url'];
            }
            break;

        case 'conference_paper':
            $conf = ! empty( $metadata['conferenceName'] ) ? $metadata['conferenceName'] : '';
            $place = ! empty( $metadata['place'] ) ? $metadata['place'] : '';

            $details = $title . ' [Internet]. presentado en: ' . $conf;
            if ( $year ) $details .= '; ' . $year;
            if ( $place ) $details .= '. ' . $place;
            if ( ! empty( $metadata['url'] ) ) {
                $details .= ' Disponible en: ' . $metadata['url'];
            }
            break;

        default:
            $details = $title . ' [Internet].';
            if ( ! empty( $metadata['websiteName'] ) ) $details .= ' ' . $metadata['websiteName'] . ';';
            if ( $year ) $details .= ' ' . $year;
            $details .= ' [citado ' . $accessed_date . '].';
            if ( ! empty( $metadata['url'] ) ) $details .= ' Disponible en: ' . $metadata['url'];
            break;
    }

    return '1. ' . trim( $author_part . $details );
}

// 8-16: ABNT, CSE, ASA, APSA, AAA, AMA, MLA, BibTeX, RIS (sin cambios; fuera del alcance de esta auditoría)
function cgwp_format_abnt( $metadata ) {
    $authors_list = array();
    if ( ! empty( $metadata['authors'] ) ) {
        foreach ( $metadata['authors'] as $auth ) {
            if ( isset( $auth['isCorporate'] ) && $auth['isCorporate'] ) {
                $authors_list[] = strtoupper( $auth['name'] );
            } else {
                $last = strtoupper( isset( $auth['lastName'] ) ? $auth['lastName'] : '' );
                $first = isset( $auth['firstName'] ) ? $auth['firstName'] : '';
                $authors_list[] = $last . ', ' . $first;
            }
        }
    }

    $authors_str = '';
    if ( count( $authors_list ) > 3 ) {
        $authors_str = $authors_list[0] . ' et al.';
    } else {
        $authors_str = implode( '; ', $authors_list );
    }

    $author_part = ! empty( $authors_str ) ? $authors_str . '. ' : '';
    $title = isset( $metadata['title'] ) ? trim( $metadata['title'] ) : 'Untitled';
    $source = isset( $metadata['sourceType'] ) ? $metadata['sourceType'] : 'website';
    $year = ! empty( $metadata['year'] ) ? $metadata['year'] : '';

    $formatted_title = '';
    if ( strpos( $title, ':' ) !== false ) {
        $parts = explode( ':', $title, 2 );
        $formatted_title = '<b>' . trim( $parts[0] ) . '</b>: ' . trim( $parts[1] );
    } else {
        $formatted_title = '<b>' . $title . '</b>';
    }

    $details = '';
    switch ( $source ) {
        case 'book':
            $details = $formatted_title;
            if ( ! empty( $metadata['place'] ) && ! empty( $metadata['publisher'] ) ) {
                $details .= '. ' . $metadata['place'] . ': ' . $metadata['publisher'];
            } elseif ( ! empty( $metadata['publisher'] ) ) {
                $details .= '. ' . $metadata['publisher'];
            }
            if ( $year ) $details .= ', ' . $year;
            $details .= '.';
            break;
        case 'journal_article':
            $details = $title . '.';
            if ( ! empty( $metadata['journalName'] ) ) $details .= ' <b>' . $metadata['journalName'] . '</b>';
            if ( ! empty( $metadata['place'] ) ) $details .= ', ' . $metadata['place'];
            if ( ! empty( $metadata['volume'] ) ) $details .= ', v. ' . $metadata['volume'];
            if ( ! empty( $metadata['issue'] ) ) $details .= ', n. ' . $metadata['issue'];
            if ( ! empty( $metadata['pages'] ) ) $details .= ', p. ' . $metadata['pages'];
            if ( $year ) $details .= ', ' . $year;
            $details .= '.';
            break;
        default:
            $details = $formatted_title;
            if ( ! empty( $metadata['websiteName'] ) ) $details .= '. <b>' . $metadata['websiteName'] . '</b>';
            if ( $year ) $details .= ', ' . $year;
            if ( ! empty( $metadata['url'] ) ) $details .= '. Available at: ' . $metadata['url'];
            break;
    }

    return trim( $author_part . $details );
}

function cgwp_format_cse( $metadata ) {
    $authors_list = array();
    if ( ! empty( $metadata['authors'] ) ) {
        foreach ( $metadata['authors'] as $auth ) {
            $authors_list[] = cgwp_get_full_author_name( $auth, 'last-init' );
        }
    }

    $authors_str = '';
    if ( count( $authors_list ) > 10 ) {
        $authors_str = implode( ', ', array_slice( $authors_list, 0, 10 ) ) . ', et al.';
    } else {
        $authors_str = implode( ', ', $authors_list );
    }

    $author_part = ! empty( $authors_str ) ? $authors_str . '. ' : 'Anon. ';
    $year = ! empty( $metadata['year'] ) ? $metadata['year'] : 'n.d.';
    $title = isset( $metadata['title'] ) ? trim( $metadata['title'] ) : 'Untitled';
    $source = isset( $metadata['sourceType'] ) ? $metadata['sourceType'] : 'website';

    $details = '';
    switch ( $source ) {
        case 'book':
            $details = $title . '.';
            if ( ! empty( $metadata['place'] ) && ! empty( $metadata['publisher'] ) ) {
                $details .= ' ' . $metadata['place'] . ': ' . $metadata['publisher'] . '.';
            }
            break;
        case 'journal_article':
            $details = $title . '.';
            if ( ! empty( $metadata['journalName'] ) ) $details .= ' <i>' . $metadata['journalName'] . '</i>.';
            if ( ! empty( $metadata['volume'] ) ) {
                $details .= ' ' . $metadata['volume'];
                if ( ! empty( $metadata['issue'] ) ) {
                    $details .= '(' . $metadata['issue'] . ')';
                }
            }
            if ( ! empty( $metadata['pages'] ) ) {
                $details .= ':' . $metadata['pages'];
            }
            $details .= '.';
            break;
        default:
            $details = $title . '.';
            if ( ! empty( $metadata['websiteName'] ) ) $details .= ' ' . $metadata['websiteName'] . ';';
            if ( ! empty( $metadata['url'] ) ) $details .= ' Available from: ' . $metadata['url'];
            break;
    }

    return trim( $author_part . $year . '. ' . $details );
}

function cgwp_format_asa( $metadata ) {
    return cgwp_format_chicago_bibliography( $metadata );
}

function cgwp_format_apsa( $metadata ) {
    $authors = '';
    if ( ! empty( $metadata['authors'] ) ) {
        $count = count( $metadata['authors'] );
        if ( $count === 1 ) {
            $authors = cgwp_get_full_author_name( $metadata['authors'][0], 'last-first' );
        } elseif ( $count === 2 ) {
            $first = cgwp_get_full_author_name( $metadata['authors'][0], 'last-first' );
            $second = cgwp_get_full_author_name( $metadata['authors'][1], 'first-last' );
            $authors = $first . ' and ' . $second;
        } else {
            $first = cgwp_get_full_author_name( $metadata['authors'][0], 'last-first' );
            $rest = array();
            for ( $i = 1; $i < $count - 1; $i++ ) {
                $rest[] = cgwp_get_full_author_name( $metadata['authors'][$i], 'first-last' );
            }
            $last = cgwp_get_full_author_name( $metadata['authors'][$count - 1], 'first-last' );
            $authors = $first . ', ' . ( ! empty( $rest ) ? implode( ', ', $rest ) . ', ' : '' ) . 'and ' . $last;
        }
    } else {
        $authors = 'Anonymous';
    }

    $title = isset( $metadata['title'] ) ? trim( $metadata['title'] ) : 'Untitled';
    $year = ! empty( $metadata['year'] ) ? $metadata['year'] : 'n.d.';
    $source = isset( $metadata['sourceType'] ) ? $metadata['sourceType'] : 'website';

    $details = '';
    switch ( $source ) {
        case 'book':
            $details = '<i>' . $title . '</i>';
            if ( ! empty( $metadata['place'] ) && ! empty( $metadata['publisher'] ) ) {
                $details .= '. ' . $metadata['place'] . ': ' . $metadata['publisher'] . '.';
            }
            break;
        case 'journal_article':
            $details = '"' . $title . '."';
            if ( ! empty( $metadata['journalName'] ) ) $details .= ' <i>' . $metadata['journalName'] . '</i>';
            if ( ! empty( $metadata['volume'] ) ) {
                $details .= ' ' . $metadata['volume'];
                if ( ! empty( $metadata['issue'] ) ) {
                    $details .= '(' . $metadata['issue'] . ')';
                }
            }
            if ( ! empty( $metadata['pages'] ) ) $details .= ': ' . $metadata['pages'] . '.';
            break;
        default:
            $details = '"' . $title . '."';
            if ( ! empty( $metadata['websiteName'] ) ) $details .= ' <i>' . $metadata['websiteName'] . '</i>.';
            if ( ! empty( $metadata['url'] ) ) $details .= ' ' . $metadata['url'] . '.';
            break;
    }

    return trim( $authors . '. ' . $year . '. ' . $details );
}

function cgwp_format_aaa( $metadata ) {
    return cgwp_format_chicago_bibliography( $metadata );
}

function cgwp_format_ama( $metadata ) {
    $authors_list = array();
    if ( ! empty( $metadata['authors'] ) ) {
        foreach ( $metadata['authors'] as $auth ) {
            $authors_list[] = cgwp_get_full_author_name( $auth, 'last-init' );
        }
    }

    $authors_str = '';
    if ( count( $authors_list ) > 6 ) {
        $authors_str = implode( ', ', array_slice( $authors_list, 0, 3 ) ) . ', et al.';
    } else {
        $authors_str = implode( ', ', $authors_list );
    }

    $author_part = ! empty( $authors_str ) ? $authors_str . '. ' : '';
    $title = isset( $metadata['title'] ) ? trim( $metadata['title'] ) : 'Untitled';
    $source = isset( $metadata['sourceType'] ) ? $metadata['sourceType'] : 'website';
    $year = ! empty( $metadata['year'] ) ? $metadata['year'] : '';

    $details = '';
    switch ( $source ) {
        case 'book':
            $details = '<i>' . $title . '</i>.';
            if ( ! empty( $metadata['place'] ) ) $details .= ' ' . $metadata['place'] . ':';
            if ( ! empty( $metadata['publisher'] ) ) $details .= ' ' . $metadata['publisher'] . ';';
            if ( $year ) $details .= ' ' . $year . '.';
            break;
        case 'journal_article':
            $details = $title . '.';
            if ( ! empty( $metadata['journalName'] ) ) $details .= ' <i>' . $metadata['journalName'] . '</i>.';
            if ( $year ) $details .= ' ' . $year . ';';
            if ( ! empty( $metadata['volume'] ) ) {
                $details .= $metadata['volume'];
                if ( ! empty( $metadata['issue'] ) ) {
                    $details .= '(' . $metadata['issue'] . ')';
                }
            }
            if ( ! empty( $metadata['pages'] ) ) $details .= ':' . $metadata['pages'] . '.';
            break;
        default:
            $details = $title . '.';
            if ( ! empty( $metadata['websiteName'] ) ) $details .= ' ' . $metadata['websiteName'] . '.';
            if ( ! empty( $metadata['url'] ) ) $details .= ' ' . $metadata['url'] . '.';
            break;
    }

    return trim( $author_part . $details );
}

function cgwp_format_mla( $metadata ) {
    $authors_str = '';
    if ( ! empty( $metadata['authors'] ) ) {
        $formatted = array();
        $is_first = true;
        foreach ( $metadata['authors'] as $auth ) {
            if ( isset( $auth['isCorporate'] ) && $auth['isCorporate'] ) {
                $formatted[] = $auth['name'];
            } else {
                if ( $is_first ) {
                    $formatted[] = cgwp_get_full_author_name( $auth, 'last-first' );
                    $is_first = false;
                } else {
                    $formatted[] = cgwp_get_full_author_name( $auth, 'first-last' );
                }
            }
        }
        $count = count( $formatted );
        if ( $count === 1 ) {
            $authors_str = $formatted[0];
        } elseif ( $count === 2 ) {
            $authors_str = $formatted[0] . ', and ' . $formatted[1];
        } else {
            $authors_str = $formatted[0] . ', et al';
        }
    }

    $title = isset( $metadata['title'] ) ? trim( $metadata['title'] ) : 'Untitled';
    $output = ! empty( $authors_str ) ? $authors_str . '. ' : '';
    $source = isset( $metadata['sourceType'] ) ? $metadata['sourceType'] : 'website';
    $year = ! empty( $metadata['year'] ) ? $metadata['year'] : '';

    switch ( $source ) {
        case 'book':
            $output .= '<i>' . $title . '</i>. ';
            if ( ! empty( $metadata['publisher'] ) ) $output .= $metadata['publisher'] . ', ';
            if ( $year ) $output .= $year . '.';
            break;
        case 'journal_article':
            $output .= '"' . $title . '." ';
            if ( ! empty( $metadata['journalName'] ) ) $output .= '<i>' . $metadata['journalName'] . '</i>, ';
            if ( ! empty( $metadata['volume'] ) ) $output .= 'vol. ' . $metadata['volume'] . ', ';
            if ( ! empty( $metadata['issue'] ) ) $output .= 'no. ' . $metadata['issue'] . ', ';
            if ( $year ) $output .= $year . ', ';
            if ( ! empty( $metadata['pages'] ) ) $output .= 'pp. ' . $metadata['pages'] . '.';
            break;
        default:
            $output .= '"' . $title . '." ';
            if ( ! empty( $metadata['websiteName'] ) ) $output .= '<i>' . $metadata['websiteName'] . '</i>, ';
            if ( $year ) $output .= $year . ', ';
            if ( ! empty( $metadata['url'] ) ) $output .= $metadata['url'] . '.';
            break;
    }

    return $output;
}

function cgwp_format_bibtex( $metadata ) {
    $source = isset( $metadata['sourceType'] ) ? $metadata['sourceType'] : 'misc';

    $type_map = array(
        'journal_article'  => 'article',
        'book'             => 'book',
        'chapter'          => 'incollection',
        'conference_paper' => 'inproceedings',
        'thesis'           => 'mastersthesis',
        'dissertation'     => 'phdthesis',
        'report'           => 'techreport',
        'website'          => 'misc',
        'newspaper'        => 'article',
        'magazine'         => 'article'
    );
    $bib_type = isset( $type_map[$source] ) ? $type_map[$source] : 'misc';

    $authors_arr = array();
    if ( ! empty( $metadata['authors'] ) ) {
        foreach ( $metadata['authors'] as $auth ) {
            if ( isset( $auth['isCorporate'] ) && $auth['isCorporate'] ) {
                $authors_arr[] = '{' . $auth['name'] . '}';
            } else {
                $authors_arr[] = cgwp_get_full_author_name( $auth, 'last-first' );
            }
        }
    }
    $authors_str = ! empty( $authors_arr ) ? implode( ' and ', $authors_arr ) : 'Unknown';

    $first_author = 'unknown';
    if ( ! empty( $metadata['authors'] ) ) {
        $first_author = isset( $metadata['authors'][0]['lastName'] ) ? $metadata['authors'][0]['lastName'] : ( isset( $metadata['authors'][0]['name'] ) ? $metadata['authors'][0]['name'] : 'unknown' );
    }
    $first_author = strtolower( preg_replace( '/\s+/', '', $first_author ) );
    $year = ! empty( $metadata['year'] ) ? $metadata['year'] : 'nd';
    $cite_key = $first_author . $year;

    $fields = array();
    $fields[] = "  author = {{$authors_str}}";
    if ( ! empty( $metadata['title'] ) ) $fields[] = "  title = {{$metadata['title']}}";
    if ( ! empty( $metadata['year'] ) ) $fields[] = "  year = {{$metadata['year']}}";
    if ( ! empty( $metadata['journalName'] ) ) $fields[] = "  journal = {{$metadata['journalName']}}";
    if ( ! empty( $metadata['bookTitle'] ) ) $fields[] = "  booktitle = {{$metadata['bookTitle']}}";
    if ( ! empty( $metadata['publisher'] ) ) $fields[] = "  publisher = {{$metadata['publisher']}}";
    if ( ! empty( $metadata['volume'] ) ) $fields[] = "  volume = {{$metadata['volume']}}";
    if ( ! empty( $metadata['issue'] ) ) $fields[] = "  number = {{$metadata['issue']}}";
    if ( ! empty( $metadata['pages'] ) ) $fields[] = "  pages = {{$metadata['pages']}}";
    if ( ! empty( $metadata['doi'] ) ) $fields[] = "  doi = {{$metadata['doi']}}";
    if ( ! empty( $metadata['url'] ) ) $fields[] = "  url = {{$metadata['url']}}";

    return "@{$bib_type}{{$cite_key},\n" . implode( ",\n", $fields ) . "\n}";
}

function cgwp_format_ris( $metadata ) {
    $source = isset( $metadata['sourceType'] ) ? $metadata['sourceType'] : 'website';

    $type_map = array(
        'journal_article'  => 'JOUR',
        'book'             => 'BOOK',
        'chapter'          => 'CHAP',
        'conference_paper' => 'CPAPER',
        'thesis'           => 'THES',
        'dissertation'     => 'THES',
        'report'           => 'RPRT',
        'website'          => 'ELEC',
        'newspaper'        => 'NEWS',
        'magazine'         => 'MGZN'
    );
    $ris_type = isset( $type_map[$source] ) ? $type_map[$source] : 'GEN';

    $lines = array();
    $lines[] = "TY  - {$ris_type}";
    if ( ! empty( $metadata['title'] ) ) $lines[] = "TI  - " . $metadata['title'];

    if ( ! empty( $metadata['authors'] ) ) {
        foreach ( $metadata['authors'] as $auth ) {
            if ( isset( $auth['isCorporate'] ) && $auth['isCorporate'] ) {
                $lines[] = "AU  - " . $auth['name'];
            } else {
                $lines[] = "AU  - " . cgwp_get_full_author_name( $auth, 'last-first' );
            }
        }
    }

    if ( ! empty( $metadata['year'] ) ) $lines[] = "PY  - " . $metadata['year'];
    if ( ! empty( $metadata['journalName'] ) ) $lines[] = "JO  - " . $metadata['journalName'];
    if ( ! empty( $metadata['bookTitle'] ) ) $lines[] = "T2  - " . $metadata['bookTitle'];
    if ( ! empty( $metadata['publisher'] ) ) $lines[] = "PB  - " . $metadata['publisher'];
    if ( ! empty( $metadata['volume'] ) ) $lines[] = "VL  - " . $metadata['volume'];
    if ( ! empty( $metadata['issue'] ) ) $lines[] = "IS  - " . $metadata['issue'];
    if ( ! empty( $metadata['pages'] ) ) {
        $parts = explode( '-', $metadata['pages'] );
        if ( isset( $parts[0] ) ) $lines[] = "SP  - " . trim( $parts[0] );
        if ( isset( $parts[1] ) ) $lines[] = "EP  - " . trim( $parts[1] );
    }
    if ( ! empty( $metadata['doi'] ) ) $lines[] = "DO  - " . $metadata['doi'];
    if ( ! empty( $metadata['url'] ) ) $lines[] = "UR  - " . $metadata['url'];
    $lines[] = "ER  - ";

    return implode( "\n", $lines );
}

// --------------------------------------------------------------------
// 5. INTERFAZ FRONTEND (CSS, JS, SHORTCODE)
// --------------------------------------------------------------------

/**
 * Módulo 5: Interfaz de usuario del Frontend.
 * Renderiza el formulario dinámico, los estilos CSS y los scripts JS
 * activados mediante el shortcode [generador_citas].
 */
add_shortcode( 'generador_citas', 'cgwp_render_generator_shortcode' );

function cgwp_render_generator_shortcode() {
    ob_start();
    ?>
    <div class="cgwp-wrapper">
        <div class="cgwp-grid">

            <!-- Columna Izquierda: Información de la Fuente -->
            <div class="cgwp-col-form">
                <div class="cgwp-card">
                    <h3 class="cgwp-card-title">Información de la Fuente</h3>

                    <!-- Búsqueda / Obtención automática -->
                    <div class="cgwp-search-box">
                        <label>Carga automática desde Identificador</label>

                        <div class="cgwp-search-input-row">
                            <div class="cgwp-search-input-wrap">
                                <input type="text" id="cgwp-search-value" placeholder="Pega un DOI, URL, ISBN o ISSN aquí..." class="cgwp-input" oninput="cgwpHandleSearchInput()">
                                <button type="button" id="cgwp-search-clear-btn" class="cgwp-input-clear-btn" style="display:none;" onclick="cgwpClearSearchInput()" title="Eliminar">Eliminar</button>
                            </div>
                            <button onclick="cgwpLoadFromIdentifier()" class="cgwp-btn cgwp-btn-secondary">Cargar</button>
                        </div>

                        <div class="cgwp-search-meta-row">
                            <span id="cgwp-detected-badge" class="cgwp-detected-badge" style="display:none;"></span>
                            <select id="cgwp-search-type" class="cgwp-input cgwp-search-type-select">
                                <option value="doi">DOI</option>
                                <option value="url">URL Web</option>
                                <option value="isbn">ISBN</option>
                                <option value="issn">ISSN</option>
                            </select>
                        </div>

                        <div id="cgwp-search-loader" class="cgwp-search-loader" style="display:none;">Buscando metadatos...</div>
                        <div id="cgwp-search-error" class="cgwp-search-error" style="display:none;"></div>
                    </div>

                    <!-- Tipo de Fuente -->
                    <div class="cgwp-form-group">
                        <label for="cgwp-manual-source-type">Tipo de Fuente</label>
                        <select id="cgwp-manual-source-type" class="cgwp-input">
                            <option value="website">Sitio Web</option>
                            <option value="book">Libro</option>
                            <option value="journal_article">Artículo Científico</option>
                            <option value="chapter">Capítulo de Libro</option>
                            <option value="conference_paper">Ponencia / Conferencia</option>
                            <option value="report">Informe / Reporte</option>
                            <option value="newspaper">Artículo de Periódico</option>
                            <option value="magazine">Artículo de Revista</option>
                            <option value="thesis">Tesis de Maestría</option>
                            <option value="dissertation">Disertación Doctoral</option>
                            <option value="ai_generative">Contenido generado por IA</option>
                        </select>
                    </div>

                    <!-- Sección Autores -->
                    <div class="cgwp-authors-section" id="cgwp-authors-section">
                        <div class="cgwp-authors-header">
                            <label>Autores</label>
                            <button onclick="cgwpAddAuthorRow()" class="cgwp-btn-link">+ Agregar Autor</button>
                        </div>
                        <div id="cgwp-authors-container">
                            <!-- Se insertará dinámicamente -->
                        </div>
                    </div>

                    <!-- Campos del Formulario -->
                    <div class="cgwp-form-group">
                        <label for="cgwp-manual-title">Título <span class="required">*</span></label>
                        <input type="text" id="cgwp-manual-title" placeholder="Título de la publicación" class="cgwp-input">
                        <span id="error-title" class="field-error-msg" style="display:none;">El título es obligatorio</span>
                    </div>

                    <div class="cgwp-form-group">
                        <label for="cgwp-manual-year">Año de Publicación <span class="required">*</span></label>
                        <input type="text" id="cgwp-manual-year" placeholder="Ej: 2026" class="cgwp-input">
                        <span id="error-year" class="field-error-msg" style="display:none;">El año es obligatorio</span>
                    </div>

                    <!-- Campos Condicionales -->
                    <div class="cgwp-form-group cgwp-conditional-field web-only">
                        <label for="cgwp-manual-sitename">Nombre del Sitio Web</label>
                        <input type="text" id="cgwp-manual-sitename" placeholder="Ej: Wikipedia" class="cgwp-input">
                    </div>

                    <div class="cgwp-form-group cgwp-conditional-field publisher-field" style="display:none;">
                        <label for="cgwp-manual-publisher">Editorial</label>
                        <input type="text" id="cgwp-manual-publisher" placeholder="Ej: Editorial Médica" class="cgwp-input">
                    </div>

                    <div class="cgwp-form-group cgwp-conditional-field place-field" style="display:none;">
                        <label for="cgwp-manual-place">Ciudad de Publicación</label>
                        <input type="text" id="cgwp-manual-place" placeholder="Ej: Latacunga" class="cgwp-input">
                    </div>

                    <div class="cgwp-form-group cgwp-conditional-field journal-field" style="display:none;">
                        <label for="cgwp-manual-journal">Nombre de la Revista / Periódico</label>
                        <input type="text" id="cgwp-manual-journal" placeholder="Ej: Nature o El País" class="cgwp-input">
                    </div>

                    <!-- NUEVO: campos exigidos por Vancouver/ICMJE, siempre manuales -->
                    <div class="cgwp-form-group cgwp-conditional-field journal-field" style="display:none;">
                        <label for="cgwp-manual-journal-abbrev">Abreviatura NLM de la revista (Vancouver)</label>
                        <input type="text" id="cgwp-manual-journal-abbrev" placeholder="Ej: N Engl J Med" class="cgwp-input">
                        <span style="font-size:12px;color:#64748b;">Consulte la abreviatura oficial en el <a href="https://www.ncbi.nlm.nih.gov/nlmcatalog/journals" target="_blank" rel="noopener">NLM Catalog</a>. Si se deja vacío, la cita Vancouver usará el nombre completo (no conforme a la norma ICMJE).</span>
                    </div>

                    <div class="cgwp-form-group cgwp-conditional-field journal-field" style="display:none;">
                        <label for="cgwp-manual-pmid">PMID (si aplica)</label>
                        <input type="text" id="cgwp-manual-pmid" placeholder="Ej: 32311318" class="cgwp-input">
                    </div>

                    <div class="cgwp-form-group cgwp-conditional-field volume-field" style="display:none;">
                        <label for="cgwp-manual-volume">Volumen</label>
                        <input type="text" id="cgwp-manual-volume" placeholder="Ej: 15" class="cgwp-input">
                    </div>

                    <div class="cgwp-form-group cgwp-conditional-field issue-field" style="display:none;">
                        <label for="cgwp-manual-issue">Número / Edición</label>
                        <input type="text" id="cgwp-manual-issue" placeholder="Ej: 4" class="cgwp-input">
                    </div>

                    <div class="cgwp-form-group cgwp-conditional-field pages-field" style="display:none;">
                        <label for="cgwp-manual-pages">Páginas</label>
                        <input type="text" id="cgwp-manual-pages" placeholder="Ej: 120-125" class="cgwp-input">
                    </div>

                    <div class="cgwp-form-group cgwp-conditional-field booktitle-field" style="display:none;">
                        <label for="cgwp-manual-booktitle">Título del Libro</label>
                        <input type="text" id="cgwp-manual-booktitle" placeholder="Ej: Avances en Tecnología" class="cgwp-input">
                    </div>

                    <div class="cgwp-form-group cgwp-conditional-field conference-field" style="display:none;">
                        <label for="cgwp-manual-conference">Nombre de la Conferencia</label>
                        <input type="text" id="cgwp-manual-conference" placeholder="Ej: Congreso de Ciencia" class="cgwp-input">
                    </div>

                    <div class="cgwp-form-group cgwp-conditional-field institution-field" style="display:none;">
                        <label for="cgwp-manual-institution">Institución / Universidad</label>
                        <input type="text" id="cgwp-manual-institution" placeholder="Ej: Universidad Nacional" class="cgwp-input">
                    </div>

                    <div class="cgwp-form-group cgwp-conditional-field degree-field" style="display:none;">
                        <label for="cgwp-manual-degree">Grado Académico</label>
                        <input type="text" id="cgwp-manual-degree" placeholder="Ej: Magíster en Educación" class="cgwp-input">
                    </div>

                    <!-- NUEVO: campos exigidos por APA7 para IA generativa -->
                    <div class="cgwp-form-group cgwp-conditional-field ai-only" style="display:none;">
                        <label for="cgwp-manual-ai-company">Compañía desarrolladora <span class="required">*</span></label>
                        <input type="text" id="cgwp-manual-ai-company" placeholder="Ej: OpenAI, Anthropic, Google" class="cgwp-input">
                        <span id="error-ai-company" class="field-error-msg" style="display:none;">La compañía es obligatoria (es el autor en APA7 para IA)</span>
                    </div>
                    <div class="cgwp-form-group cgwp-conditional-field ai-only" style="display:none;">
                        <label for="cgwp-manual-ai-version">Versión del modelo</label>
                        <input type="text" id="cgwp-manual-ai-version" placeholder="Ej: GPT-4, Gemini 2.5" class="cgwp-input">
                    </div>

                    <div class="cgwp-form-group">
                        <label for="cgwp-manual-url">URL (Enlace)</label>
                        <input type="text" id="cgwp-manual-url" placeholder="Ej: https://..." class="cgwp-input">
                    </div>

                    <div class="cgwp-form-group">
                        <label for="cgwp-lang">Idioma de Cita</label>
                        <select id="cgwp-lang" class="cgwp-input">
                            <option value="es">Español</option>
                            <option value="en">Inglés</option>
                        </select>
                    </div>

                    <div class="cgwp-btn-group">
                        <button onclick="cgwpGenerateCitations()" class="cgwp-btn cgwp-btn-primary">Generar Citas</button>
                        <button onclick="cgwpClearFields()" class="cgwp-btn cgwp-btn-clear">Limpiar Campos</button>
                    </div>
                </div>
            </div>

            <!-- Columna Derecha: Exportación y Formatos -->
            <div class="cgwp-col-results">

                <!-- Exportar Gestores -->
                <div class="cgwp-card cgwp-card-export">
                    <h3 class="cgwp-card-title">Exportar para Gestores de Referencias</h3>
                    <p>Descarga en formato RIS para importar en Zotero, Mendeley, EndNote, etc.</p>
                    <button onclick="cgwpDownloadRIS()" id="cgwp-btn-ris" class="cgwp-btn cgwp-btn-success" disabled>Descargar archivo RIS</button>
                    <div class="cgwp-compatibility">Compatible con: Zotero • Mendeley • EndNote • RefWorks • Papers • Citavi</div>
                </div>

                <!-- Filtrar Formatos -->
                <div class="cgwp-card">
                    <h3 class="cgwp-card-title">Filtrar Formatos</h3>
                    <div class="cgwp-form-group">
                        <label for="cgwp-filter-discipline">Filtrar por disciplina</label>
                        <select id="cgwp-filter-discipline" onchange="cgwpApplyFilter()" class="cgwp-input">
                            <option value="all" selected>Todos los formatos</option>
                            <option value="general">General</option>
                            <option value="tecnico">Técnico</option>
                            <option value="medicina">Medicina</option>
                            <option value="historia">Historia / Literatura</option>
                        </select>
                    </div>
                </div>

                <!-- Lista de Citas Generadas -->
                <div id="cgwp-citations-list" class="cgwp-citations-container">

                    <!-- APA 7: referencia -->
                    <div class="cgwp-citation-card" data-discipline="general" id="card-apa7">
                        <div class="cgwp-cit-header">
                            <span class="cgwp-cit-name">APA 7ma Edición — Referencia</span>
                            <span class="cgwp-badge badge-general">General</span>
                        </div>
                        <p class="cgwp-cit-desc">American Psychological Association (7ma ed.)</p>
                        <div class="cgwp-cit-box">Rellena el formulario de la izquierda para generar la cita.</div>
                        <button onclick="cgwpCopy(this)" class="cgwp-btn-copy">Copiar</button>
                    </div>

                    <!-- APA 7: cita narrativa -->
                    <div class="cgwp-citation-card" data-discipline="general" id="card-apa7_narrative">
                        <div class="cgwp-cit-header">
                            <span class="cgwp-cit-name">APA 7 — Cita narrativa</span>
                            <span class="cgwp-badge badge-general">General</span>
                        </div>
                        <p class="cgwp-cit-desc">Autor (Año), usada dentro de la oración</p>
                        <div class="cgwp-cit-box">Rellena el formulario de la izquierda para generar la cita.</div>
                        <button onclick="cgwpCopy(this)" class="cgwp-btn-copy">Copiar</button>
                    </div>

                    <!-- APA 7: cita parentética -->
                    <div class="cgwp-citation-card" data-discipline="general" id="card-apa7_parenthetical">
                        <div class="cgwp-cit-header">
                            <span class="cgwp-cit-name">APA 7 — Cita parentética</span>
                            <span class="cgwp-badge badge-general">General</span>
                        </div>
                        <p class="cgwp-cit-desc">(Autor, Año), al final de la oración</p>
                        <div class="cgwp-cit-box">Rellena el formulario de la izquierda para generar la cita.</div>
                        <button onclick="cgwpCopy(this)" class="cgwp-btn-copy">Copiar</button>
                    </div>

                    <!-- IEEE -->
                    <div class="cgwp-citation-card" data-discipline="tecnico" id="card-ieee">
                        <div class="cgwp-cit-header">
                            <span class="cgwp-cit-name">IEEE</span>
                            <span class="cgwp-badge badge-tecnico">Técnico</span>
                        </div>
                        <p class="cgwp-cit-desc">Institute of Electrical and Electronics Engineers</p>
                        <div class="cgwp-cit-box">Rellena el formulario de la izquierda para generar la cita.</div>
                        <button onclick="cgwpCopy(this)" class="cgwp-btn-copy">Copiar</button>
                    </div>

                    <!-- Vancouver -->
                    <div class="cgwp-citation-card" data-discipline="medicina" id="card-vancouver">
                        <div class="cgwp-cit-header">
                            <span class="cgwp-cit-name">Vancouver</span>
                            <span class="cgwp-badge badge-medicina">Medicina</span>
                        </div>
                        <p class="cgwp-cit-desc">International Committee of Medical Journal Editors (ICMJE)</p>
                        <div class="cgwp-cit-box">Rellena el formulario de la izquierda para generar la cita.</div>
                        <button onclick="cgwpCopy(this)" class="cgwp-btn-copy">Copiar</button>
                    </div>

                    <!-- Chicago 18 - Notes&Bibliography: Bibliografía -->
                    <div class="cgwp-citation-card" data-discipline="historia" id="card-chicago_bibliography">
                        <div class="cgwp-cit-header">
                            <span class="cgwp-cit-name">Chicago 18 (N&amp;B) — Bibliografía</span>
                            <span class="cgwp-badge badge-historia">Historia / Literatura</span>
                        </div>
                        <p class="cgwp-cit-desc">Chicago Manual of Style, 18ª ed. — Notes and Bibliography</p>
                        <div class="cgwp-cit-box">Rellena el formulario de la izquierda para generar la cita.</div>
                        <button onclick="cgwpCopy(this)" class="cgwp-btn-copy">Copiar</button>
                    </div>

                    <!-- Chicago 18 - Nota completa -->
                    <div class="cgwp-citation-card" data-discipline="historia" id="card-chicago_note_full">
                        <div class="cgwp-cit-header">
                            <span class="cgwp-cit-name">Chicago 18 (N&amp;B) — Nota completa</span>
                            <span class="cgwp-badge badge-historia">Historia / Literatura</span>
                        </div>
                        <p class="cgwp-cit-desc">Primera cita a pie de página o nota final</p>
                        <div class="cgwp-cit-box">Rellena el formulario de la izquierda para generar la cita.</div>
                        <button onclick="cgwpCopy(this)" class="cgwp-btn-copy">Copiar</button>
                    </div>

                    <!-- Chicago 18 - Nota abreviada -->
                    <div class="cgwp-citation-card" data-discipline="historia" id="card-chicago_note_short">
                        <div class="cgwp-cit-header">
                            <span class="cgwp-cit-name">Chicago 18 (N&amp;B) — Nota abreviada</span>
                            <span class="cgwp-badge badge-historia">Historia / Literatura</span>
                        </div>
                        <p class="cgwp-cit-desc">Para citas repetidas de la misma fuente</p>
                        <div class="cgwp-cit-box">Rellena el formulario de la izquierda para generar la cita.</div>
                        <button onclick="cgwpCopy(this)" class="cgwp-btn-copy">Copiar</button>
                    </div>

                    <!-- Chicago 18 - Author-Date: cita en texto -->
                    <div class="cgwp-citation-card" data-discipline="historia" id="card-chicago_authordate_intext">
                        <div class="cgwp-cit-header">
                            <span class="cgwp-cit-name">Chicago 18 (Author-Date) — Cita en texto</span>
                            <span class="cgwp-badge badge-historia">Historia / Literatura</span>
                        </div>
                        <p class="cgwp-cit-desc">(Autor Año), estilo ciencias sociales</p>
                        <div class="cgwp-cit-box">Rellena el formulario de la izquierda para generar la cita.</div>
                        <button onclick="cgwpCopy(this)" class="cgwp-btn-copy">Copiar</button>
                    </div>

                    <!-- Chicago 18 - Author-Date: referencia -->
                    <div class="cgwp-citation-card" data-discipline="historia" id="card-chicago_authordate_reference">
                        <div class="cgwp-cit-header">
                            <span class="cgwp-cit-name">Chicago 18 (Author-Date) — Referencia</span>
                            <span class="cgwp-badge badge-historia">Historia / Literatura</span>
                        </div>
                        <p class="cgwp-cit-desc">Entrada de lista de referencias del sistema Author-Date</p>
                        <div class="cgwp-cit-box">Rellena el formulario de la izquierda para generar la cita.</div>
                        <button onclick="cgwpCopy(this)" class="cgwp-btn-copy">Copiar</button>
                    </div>

                     <style>
        .cgwp-wrapper {
            max-width: 1200px;
            margin: 20px auto;
            font-family: inherit;
            color: #334155;
            padding: 0 10px;
            box-sizing: border-box;
        }
        .cgwp-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
        }
        @media(min-width: 900px) {
            .cgwp-grid {
                grid-template-columns: 1fr 1fr;
            }
        }
        .cgwp-card {
            background: #ffffff;
            border-radius: 8px;
            padding: 16px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.04);
            margin-bottom: 20px;
            border: 1px solid #e2e8f0;
            box-sizing: border-box;
        }
        @media(min-width: 600px) {
            .cgwp-card {
                padding: 24px;
            }
        }
        .cgwp-card-title {
            font-size: 18px;
            font-weight: 700;
            margin-top: 0;
            margin-bottom: 16px;
            color: #005691;
            border-bottom: 2px solid #005691;
            padding-bottom: 6px;
            display: inline-block;
            font-family: inherit;
        }
        .cgwp-search-box {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .cgwp-search-box label {
            font-size: 13px;
            font-weight: 600;
            color: #475569;
            margin-bottom: 6px;
            display: block;
            font-family: inherit;
        }
        .cgwp-search-input-row {
            display: flex;
            gap: 8px;
            align-items: stretch;
        }
        .cgwp-search-input-wrap {
            position: relative;
            flex: 1;
            min-width: 0;
        }
        .cgwp-search-input-wrap .cgwp-input {
            padding-right: 78px;
        }
        .cgwp-input-clear-btn {
            position: absolute;
            top: 50%;
            right: 6px;
            transform: translateY(-50%);
            background: #fee2e2;
            color: #b91c1c;
            border: none;
            border-radius: 4px;
            padding: 5px 10px;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            font-family: inherit;
            transition: background 0.2s ease;
        }
        .cgwp-input-clear-btn:hover {
            background: #fecaca;
        }
        .cgwp-search-meta-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            margin-top: 10px;
        }
        .cgwp-detected-badge {
            font-size: 11px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 12px;
            background: #dcfce7;
            color: #166534;
            text-transform: uppercase;
            letter-spacing: 0.02em;
            font-family: inherit;
            white-space: nowrap;
        }
        .cgwp-search-type-select {
            width: auto;
            min-width: 130px;
            margin-left: auto;
            font-size: 12px;
            padding: 6px 10px;
        }
        @media(max-width: 580px) {
            .cgwp-search-input-row {
                flex-direction: column;
            }
            .cgwp-search-input-row button {
                width: 100% !important;
            }
            .cgwp-search-meta-row {
                flex-wrap: wrap;
            }
            .cgwp-search-type-select {
                margin-left: 0;
                width: 100%;
            }
        }
        .cgwp-input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 14px;
            color: #1e293b;
            background: #ffffff;
            box-sizing: border-box;
            font-family: inherit;
        }
        .cgwp-input:focus {
            border-color: #005691;
            outline: none;
            box-shadow: 0 0 0 2px rgba(0, 86, 145, 0.2);
        }
        select.cgwp-input {
            width: auto;
            min-width: 100px;
        }
        .cgwp-btn {
            padding: 10px 18px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s ease;
            white-space: nowrap;
            font-family: inherit;
            box-sizing: border-box;
        }
        .cgwp-btn-primary {
            background: #005691;
            color: white;
            padding: 12px;
            font-size: 15px;
        }
        .cgwp-btn-primary:hover {
            background: #004370;
        }
        .cgwp-btn-clear {
            background: #64748b;
            color: white;
            padding: 12px;
            font-size: 15px;
        }
        .cgwp-btn-clear:hover {
            background: #475569;
        }
        .cgwp-btn-group {
            display: flex;
            gap: 12px;
            margin-top: 16px;
        }
        .cgwp-btn-group .cgwp-btn {
            flex: 1;
            width: auto;
            margin: 0;
        }
        @media(max-width: 480px) {
            .cgwp-btn-group {
                flex-direction: column;
            }
            .cgwp-btn-group .cgwp-btn {
                width: 100%;
            }
        }
        .cgwp-btn-secondary {
            background: #005691;
            color: white;
        }
        .cgwp-btn-secondary:hover {
            background: #004370;
        }
        .cgwp-btn-success {
            background: #155724;
            color: white;
            width: 100%;
        }
        .cgwp-btn-success:hover {
            background: #0c3e17;
        }
        .cgwp-btn-success:disabled {
            background: #cbd5e1;
            color: #94a3b8;
            cursor: not-allowed;
        }
        .cgwp-btn-link {
            background: transparent;
            border: none;
            color: #005691;
            font-weight: 600;
            cursor: pointer;
            font-size: 13px;
            font-family: inherit;
        }
        .cgwp-btn-link:hover {
            text-decoration: underline;
        }
        .cgwp-form-group {
            margin-bottom: 16px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .cgwp-form-group label {
            font-size: 14px;
            font-weight: 600;
            color: #334155;
            font-family: inherit;
        }
        .required {
            color: #ef4444;
        }
        .field-error-msg {
            color: #ef4444;
            font-size: 12px;
            font-weight: 500;
            font-family: inherit;
        }
        .cgwp-authors-section {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 16px;
        }
        .cgwp-authors-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        .cgwp-authors-header label {
            font-size: 14px;
            font-weight: 600;
            color: #334155;
            font-family: inherit;
        }
        .cgwp-author-row-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 8px;
            position: relative;
        }
        .cgwp-author-inputs {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-top: 4px;
        }
        @media(max-width: 580px) {
            .cgwp-author-inputs {
                grid-template-columns: 1fr;
                gap: 6px;
            }
        }
        .cgwp-author-inputs .cgwp-form-group {
            margin-bottom: 0;
        }
        .cgwp-author-inputs label {
            font-size: 11px;
            color: #64748b;
            font-family: inherit;
        }
        .cgwp-btn-remove-auth {
            position: absolute;
            top: 10px;
            right: 10px;
            background: transparent;
            border: none;
            color: #ef4444;
            font-size: 12px;
            cursor: pointer;
            font-family: inherit;
        }
        .cgwp-btn-remove-auth:hover {
            text-decoration: underline;
        }
        .cgwp-card-export {
            border: 1px solid #c3e6cb;
            background: #d4edda;
        }
        .cgwp-card-export .cgwp-card-title {
            border-bottom-color: #155724;
            color: #155724;
        }
        .cgwp-card-export p {
            font-size: 13px;
            color: #155724;
            margin-top: 0;
            margin-bottom: 12px;
            font-family: inherit;
        }
        .cgwp-compatibility {
            font-size: 11px;
            color: #155724;
            text-align: center;
            margin-top: 8px;
            font-family: inherit;
        }
        .cgwp-citation-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 12px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            position: relative;
            box-sizing: border-box;
        }
        .cgwp-cit-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 4px;
        }
        .cgwp-cit-name {
            font-weight: 700;
            font-size: 15px;
            color: #1e293b;
            font-family: inherit;
        }
        .cgwp-cit-desc {
            font-size: 12px;
            color: #64748b;
            margin: 0 0 10px 0;
            font-family: inherit;
        }
        .cgwp-cit-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 12px;
            border-radius: 6px;
            font-size: 13.5px;
            line-height: 1.5;
            color: #334155;
            word-break: break-word;
            margin-bottom: 8px;
            font-family: inherit;
        }
        .cgwp-badge {
            font-size: 10px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 12px;
            text-transform: uppercase;
            font-family: inherit;
        }
        .badge-general { background: #dbeafe; color: #1e40af; }
        .badge-tecnico { background: #e0f2fe; color: #0369a1; }
        .badge-historia { background: #fef3c7; color: #92400e; }
        .badge-ciencias { background: #f3e8ff; color: #6b21a8; }
        .badge-sociologia { background: #ecfdf5; color: #065f46; }
        .badge-politicas { background: #fff1f2; color: #9f1239; }
        .badge-antropologia { background: #ffedd5; color: #9a3412; }
        .badge-medicina { background: #ffe4e6; color: #9f1239; }
        .badge-humanidades { background: #f1f5f9; color: #334155; }
        .badge-regional { background: #e0e7ff; color: #3730a3; }
        .cgwp-btn-copy {
            background: #f97316;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            float: right;
            transition: background 0.2s ease;
            font-family: inherit;
        }
        .cgwp-btn-copy:hover {
            background: #ea580c;
        }
        .cgwp-search-loader {
            color: #005691;
            font-size: 12px;
            font-weight: 600;
            margin-top: 6px;
            font-family: inherit;
        }
        .cgwp-search-error {
            color: #ef4444;
            font-size: 12px;
            font-weight: 600;
            margin-top: 6px;
            font-family: inherit;
        }
    </style>

    <script>
        let cgwpAuthorCount = 0;
        let cgwpCurrentRis = '';

        // Etiquetas de las tarjetas que se actualizan tras generar/cargar citas
        const CGWP_CARD_KEYS = [
            'apa7', 'apa7_narrative', 'apa7_parenthetical', 'ieee', 'vancouver',
            'chicago_bibliography', 'chicago_note_full', 'chicago_note_short',
            'chicago_authordate_intext', 'chicago_authordate_reference'
        ];

        function cgwpAddAuthorRow(first = '', middle = '', last = '', secondLast = '') {
            cgwpAuthorCount++;
            const container = document.getElementById('cgwp-authors-container');
            const rowId = 'cgwp-author-row-' + cgwpAuthorCount;

            const html = `
                <div class="cgwp-author-row-card" id="${rowId}">
                    <button type="button" onclick="cgwpRemoveAuthorRow('${rowId}')" class="cgwp-btn-remove-auth">Eliminar</button>
                    <label>Autor ${cgwpAuthorCount}</label>
                    <div class="cgwp-author-inputs">
                        <div class="cgwp-form-group">
                            <label>Primer Nombre <span class="required">*</span></label>
                            <input type="text" class="cgwp-input auth-first" value="${first}" placeholder="Ej: Juan">
                        </div>
                        <div class="cgwp-form-group">
                            <label>Segundo Nombre</label>
                            <input type="text" class="cgwp-input auth-middle" value="${middle}" placeholder="Ej: Carlos">
                        </div>
                        <div class="cgwp-form-group">
                            <label>Primer Apellido <span class="required">*</span></label>
                            <input type="text" class="cgwp-input auth-last" value="${last}" placeholder="Ej: García">
                        </div>
                        <div class="cgwp-form-group">
                            <label>Segundo Apellido</label>
                            <input type="text" class="cgwp-input auth-second-last" value="${secondLast}" placeholder="Ej: López">
                        </div>
                    </div>
                </div>
            `;
            container.insertAdjacentHTML('beforeend', html);
        }

        function cgwpRemoveAuthorRow(rowId) {
            const row = document.getElementById(rowId);
            if (row) row.remove();
        }

        document.getElementById('cgwp-manual-source-type').addEventListener('change', function() {
            const val = this.value;
            document.querySelectorAll('.cgwp-conditional-field').forEach(el => el.style.display = 'none');

            // Por defecto, la sección de autores se muestra; solo se oculta para IA generativa
            document.getElementById('cgwp-authors-section').style.display = 'block';

            if (val === 'website') {
                document.querySelector('.web-only').style.display = 'block';
            } else if (val === 'book') {
                document.querySelector('.publisher-field').style.display = 'block';
                document.querySelector('.place-field').style.display = 'block';
            } else if (val === 'journal_article') {
                document.querySelectorAll('.journal-field').forEach(el => el.style.display = 'block');
                document.querySelector('.volume-field').style.display = 'block';
                document.querySelector('.issue-field').style.display = 'block';
                document.querySelector('.pages-field').style.display = 'block';
            } else if (val === 'chapter') {
                document.querySelector('.publisher-field').style.display = 'block';
                document.querySelector('.place-field').style.display = 'block';
                document.querySelector('.booktitle-field').style.display = 'block';
                document.querySelector('.pages-field').style.display = 'block';
            } else if (val === 'conference_paper') {
                document.querySelector('.publisher-field').style.display = 'block';
                document.querySelector('.place-field').style.display = 'block';
                document.querySelector('.conference-field').style.display = 'block';
                document.querySelector('.pages-field').style.display = 'block';
            } else if (val === 'report') {
                document.querySelector('.publisher-field').style.display = 'block';
                document.querySelector('.place-field').style.display = 'block';
                document.querySelector('.institution-field').style.display = 'block';
            } else if (val === 'newspaper' || val === 'magazine') {
                document.querySelectorAll('.journal-field').forEach(el => el.style.display = 'block');
                document.querySelector('.pages-field').style.display = 'block';
                if (val === 'magazine') {
                    document.querySelector('.volume-field').style.display = 'block';
                    document.querySelector('.issue-field').style.display = 'block';
                }
            } else if (val === 'thesis' || val === 'dissertation') {
                document.querySelector('.institution-field').style.display = 'block';
                document.querySelector('.degree-field').style.display = 'block';
            } else if (val === 'ai_generative') {
                // APA7: el autor de contenido de IA generativa SIEMPRE es la compañía (autor corporativo).
                // Se oculta la sección de autores para no permitir un autor-persona en este tipo de fuente.
                document.getElementById('cgwp-authors-section').style.display = 'none';
                document.querySelectorAll('.ai-only').forEach(el => el.style.display = 'block');
            }
        });

        // NUEVO: detecta el tipo de identificador (DOI, URL, ISSN, ISBN) a partir del texto pegado
        // por el usuario, y devuelve tanto el tipo como una etiqueta legible para el badge.
        function cgwpDetectIdentifierType(raw) {
            const value = (raw || '').trim();
            if (!value) return null;

            // URL (http/https) que no sea específicamente un enlace doi.org
            if (/^https?:\/\//i.test(value) && !/doi\.org\//i.test(value)) {
                return { type: 'url', label: 'URL detectada' };
            }

            // DOI: "10.xxxx/..." con o sin prefijo doi.org / https://doi.org
            if (/(^|\/)10\.\d{4,9}\/\S+/.test(value) || /doi\.org\//i.test(value)) {
                return { type: 'doi', label: 'DOI detectado' };
            }

            // ISSN: formato XXXX-XXXX (con o sin guion, 8 caracteres numéricos + posible X final)
            const cleanIssn = value.replace(/^issn(-l)?\s*/i, '').replace(/[^0-9Xx-]/g, '');
            if (/^\d{4}-?\d{3}[\dXx]$/.test(cleanIssn) && cleanIssn.replace(/-/g, '').length === 8) {
                return { type: 'issn', label: 'ISSN detectado' };
            }

            // ISBN: 10 o 13 dígitos (permitiendo guiones/espacios y X final en ISBN-10)
            const cleanIsbn = value.replace(/[-\s]/g, '');
            if (/^(97[89]\d{10}|\d{9}[\dXx])$/.test(cleanIsbn)) {
                return { type: 'isbn', label: 'ISBN detectado' };
            }

            return null;
        }

        // Se ejecuta en cada tecleo/pegado del campo de identificador:
        // muestra/oculta el botón "Eliminar" y actualiza el badge + el selector de tipo.
        function cgwpHandleSearchInput() {
            const input = document.getElementById('cgwp-search-value');
            const clearBtn = document.getElementById('cgwp-search-clear-btn');
            const badge = document.getElementById('cgwp-detected-badge');
            const typeSelect = document.getElementById('cgwp-search-type');
            const value = input.value;

            clearBtn.style.display = value.trim() ? 'inline-block' : 'none';

            const detected = cgwpDetectIdentifierType(value);
            if (detected) {
                badge.style.display = 'inline-block';
                badge.innerText = detected.label;
                typeSelect.value = detected.type;
            } else {
                badge.style.display = 'none';
            }
        }

        // Botón "Eliminar": limpia solo el campo de identificador (no todo el formulario)
        function cgwpClearSearchInput() {
            const input = document.getElementById('cgwp-search-value');
            input.value = '';
            input.focus();
            cgwpHandleSearchInput();
            document.getElementById('cgwp-search-error').style.display = 'none';
        }

        function cgwpApplyFilter() {
            const discipline = document.getElementById('cgwp-filter-discipline').value;
            const cards = document.querySelectorAll('.cgwp-citation-card');
            cards.forEach(card => {
                if (discipline === 'all' || card.getAttribute('data-discipline') === discipline) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        function cgwpClearFields() {
            document.getElementById('cgwp-search-value').value = '';
            document.getElementById('cgwp-search-error').style.display = 'none';
            cgwpHandleSearchInput(); // oculta el botón Eliminar y el badge de tipo detectado

            document.getElementById('cgwp-manual-title').value = '';
            document.getElementById('cgwp-manual-year').value = '';
            document.getElementById('cgwp-manual-publisher').value = '';
            document.getElementById('cgwp-manual-place').value = '';
            document.getElementById('cgwp-manual-journal').value = '';
            document.getElementById('cgwp-manual-journal-abbrev').value = '';
            document.getElementById('cgwp-manual-pmid').value = '';
            document.getElementById('cgwp-manual-volume').value = '';
            document.getElementById('cgwp-manual-issue').value = '';
            document.getElementById('cgwp-manual-pages').value = '';
            document.getElementById('cgwp-manual-booktitle').value = '';
            document.getElementById('cgwp-manual-url').value = '';
            document.getElementById('cgwp-manual-ai-company').value = '';
            document.getElementById('cgwp-manual-ai-version').value = '';
            document.getElementById('cgwp-manual-ai-company').style.borderColor = '#cbd5e1';

            document.getElementById('cgwp-manual-source-type').value = 'website';
            document.getElementById('cgwp-manual-source-type').dispatchEvent(new Event('change'));

            document.getElementById('error-title').style.display = 'none';
            document.getElementById('error-year').style.display = 'none';
            document.getElementById('error-ai-company').style.display = 'none';

            document.getElementById('cgwp-authors-container').innerHTML = '';
            cgwpAuthorCount = 0;
            cgwpAddAuthorRow();

            const defaultMsg = 'Rellena el formulario de la izquierda para generar la cita.';
            CGWP_CARD_KEYS.forEach(key => {
                const card = document.getElementById('card-' + key);
                if (card) {
                    card.querySelector('.cgwp-cit-box').innerHTML = defaultMsg;
                }
            });

            cgwpCurrentRis = '';
            document.getElementById('cgwp-btn-ris').setAttribute('disabled', 'true');
        }

        async function cgwpLoadFromIdentifier() {
            const type = document.getElementById('cgwp-search-type').value;
            const value = document.getElementById('cgwp-search-value').value;
            const loader = document.getElementById('cgwp-search-loader');
            const error = document.getElementById('cgwp-search-error');

            if (!value) return;

            loader.style.display = 'block';
            error.style.display = 'none';

            try {
                const response = await fetch('/wp-json/citation-generator/v1/generate', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ type, value, lang: 'es' })
                });
                const data = await response.json();
                loader.style.display = 'none';

                if (data.success) {
                    document.getElementById('cgwp-authors-container').innerHTML = '';
                    cgwpAuthorCount = 0;

                    const meta = data.metadata;
                    if (meta.sourceType) {
                        document.getElementById('cgwp-manual-source-type').value = meta.sourceType;
                        document.getElementById('cgwp-manual-source-type').dispatchEvent(new Event('change'));
                    }

                    document.getElementById('cgwp-manual-title').value = meta.title || '';
                    document.getElementById('cgwp-manual-year').value = meta.year || '';
                    document.getElementById('cgwp-manual-publisher').value = meta.publisher || '';
                    document.getElementById('cgwp-manual-place').value = meta.place || '';
                    document.getElementById('cgwp-manual-journal').value = meta.journalName || '';
                    // La abreviatura NLM y el PMID NUNCA se infieren automáticamente (norma ICMJE):
                    // se dejan vacíos incluso si la carga fue por DOI/ISSN, para que el usuario los confirme.
                    document.getElementById('cgwp-manual-journal-abbrev').value = '';
                    document.getElementById('cgwp-manual-pmid').value = meta.pmid || '';
                    document.getElementById('cgwp-manual-volume').value = meta.volume || '';
                    document.getElementById('cgwp-manual-issue').value = meta.issue || '';
                    document.getElementById('cgwp-manual-pages').value = meta.pages || '';
                    document.getElementById('cgwp-manual-booktitle').value = meta.bookTitle || '';
                    document.getElementById('cgwp-manual-url').value = meta.url || '';

                    if (meta.authors && meta.authors.length > 0) {
                        meta.authors.forEach(auth => {
                            cgwpAddAuthorRow(auth.firstName, auth.middleName, auth.lastName, auth.secondLastName);
                        });
                    } else {
                        cgwpAddAuthorRow();
                    }

                    cgwpUpdateCitationCards(data.citations);
                    cgwpCurrentRis = data.citations.ris || '';
                    document.getElementById('cgwp-btn-ris').removeAttribute('disabled');
                } else {
                    error.innerText = data.error || 'Identificador no encontrado.';
                    error.style.display = 'block';
                }
            } catch (err) {
                loader.style.display = 'none';
                error.innerText = 'Error al conectar con la API.';
                error.style.display = 'block';
            }
        }

        async function cgwpGenerateCitations() {
            let valid = true;
            const sourceType = document.getElementById('cgwp-manual-source-type').value;

            const titleInput = document.getElementById('cgwp-manual-title');
            const yearInput = document.getElementById('cgwp-manual-year');

            if (!titleInput.value.trim()) {
                document.getElementById('error-title').style.display = 'block';
                valid = false;
            } else {
                document.getElementById('error-title').style.display = 'none';
            }

            if (!yearInput.value.trim()) {
                document.getElementById('error-year').style.display = 'block';
                valid = false;
            } else {
                document.getElementById('error-year').style.display = 'none';
            }

            let authors = [];

            if (sourceType === 'ai_generative') {
                // APA7: autor obligatorio = compañía (autor corporativo). Nunca se completa por defecto.
                const companyInput = document.getElementById('cgwp-manual-ai-company');
                const company = companyInput.value.trim();
                if (!company) {
                    document.getElementById('error-ai-company').style.display = 'block';
                    companyInput.style.borderColor = '#ef4444';
                    valid = false;
                } else {
                    document.getElementById('error-ai-company').style.display = 'none';
                    companyInput.style.borderColor = '#cbd5e1';
                    authors = [{ name: company, isCorporate: true }];
                }
            } else {
                const authorRows = document.querySelectorAll('.cgwp-author-row-card');
                authorRows.forEach(row => {
                    const first = row.querySelector('.auth-first');
                    const last = row.querySelector('.auth-last');
                    if (!first.value.trim() || !last.value.trim()) {
                        row.style.borderColor = '#ef4444';
                        valid = false;
                    } else {
                        row.style.borderColor = '#e2e8f0';
                    }
                });

                authorRows.forEach(row => {
                    authors.push({
                        firstName: row.querySelector('.auth-first').value,
                        middleName: row.querySelector('.auth-middle').value,
                        lastName: row.querySelector('.auth-last').value,
                        secondLastName: row.querySelector('.auth-second-last').value,
                        isCorporate: false
                    });
                });
            }

            if (!valid) return;

            const metadata = {
                title: titleInput.value,
                year: yearInput.value,
                publisher: document.getElementById('cgwp-manual-publisher').value,
                place: document.getElementById('cgwp-manual-place').value,
                journalName: document.getElementById('cgwp-manual-journal').value,
                journalAbbrev: document.getElementById('cgwp-manual-journal-abbrev').value,
                pmid: document.getElementById('cgwp-manual-pmid').value,
                volume: document.getElementById('cgwp-manual-volume').value,
                issue: document.getElementById('cgwp-manual-issue').value,
                pages: document.getElementById('cgwp-manual-pages').value,
                bookTitle: document.getElementById('cgwp-manual-booktitle').value,
                conferenceName: document.getElementById('cgwp-manual-conference').value,
                institution: document.getElementById('cgwp-manual-institution').value,
                degree: document.getElementById('cgwp-manual-degree').value,
                url: document.getElementById('cgwp-manual-url').value,
                aiVersion: document.getElementById('cgwp-manual-ai-version').value,
                authors: authors
            };

            const lang = document.getElementById('cgwp-lang').value;

            try {
                const response = await fetch('/wp-json/citation-generator/v1/generate', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ type: 'manual', metadata, lang, sourceType })
                });
                const data = await response.json();

                if (data.success) {
                    cgwpUpdateCitationCards(data.citations);
                    cgwpCurrentRis = data.citations.ris || '';
                    document.getElementById('cgwp-btn-ris').removeAttribute('disabled');
                }
            } catch (err) {
                console.error("Error al generar citas:", err);
            }
        }

        function cgwpUpdateCitationCards(citations) {
            CGWP_CARD_KEYS.forEach(key => {
                const card = document.getElementById('card-' + key);
                if (card && citations[key]) {
                    card.querySelector('.cgwp-cit-box').innerHTML = citations[key];
                }
            });
        }

        function cgwpCopy(btn) {
            const citationBox = btn.parentNode.querySelector('.cgwp-cit-box');
            const temp = document.createElement('textarea');
            temp.value = citationBox.innerText || citationBox.textContent;
            document.body.appendChild(temp);
            temp.select();
            document.execCommand('copy');
            document.body.removeChild(temp);

            const originalText = btn.innerText;
            btn.innerText = 'Copiado';
            btn.style.background = '#10b981';
            setTimeout(() => {
                btn.innerText = originalText;
                btn.style.background = '#f97316';
            }, 2000);
        }

        function cgwpDownloadRIS() {
            if (!cgwpCurrentRis) return;
            const blob = new Blob([cgwpCurrentRis], { type: 'text/plain;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'referencia.ris';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        }

        cgwpAddAuthorRow();
    </script>
    <?php
    return ob_get_clean();
}
