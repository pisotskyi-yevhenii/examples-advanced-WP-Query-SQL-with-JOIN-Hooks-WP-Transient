<?php
function nftsInCollection(int $collectionId): array
{
    return get_posts([
        'post_type' => 'artwork',
        'posts_per_page' => -1,
        'meta_query' => [
            [
                'key' => 'collection',
                'value' => sprintf(':"%s";', $collectionId),
                'compare' => 'LIKE'
            ]
        ]
    ]);
}

function postsCreatedByUser(int $userId, string $postType): array
{
    $args = [
        'post_type' => $postType,
        'posts_per_page' => -1,
        'author' => $userId
    ];

    if ('collection' == $postType) {
        $args['meta_query'] = [
            'relation' => 'OR',
            [
                'key' => 'status',
                'value' => ['in_progress', 'listed'],
                'compare' => 'IN'
            ],
            [
                'relation' => 'AND',
                [
                    'key' => 'status',
                    'value' => 'sold'
                ],
                [
                    'key' => 'choose_who_you_are',
                    'value' => 'creator'
                ]
            ]
        ];
    }

    // Customer requirement: on tabs of user profile page displaying NFTs which were not attached to any collections.
    if ('artwork' == $postType) {
        $args['meta_query'] = [
            'relation' => 'OR',
            [
                'relation' => 'AND',
                [
                    'key' => 'status',
                    'value' => ['in_progress', 'listed'],
                    'compare' => 'IN'
                ],
                [
                    'key' => 'collection',
                    'value' => '',
                    'compare' => '='
                ]
            ],
            [
                'relation' => 'AND',
                [
                    'key' => 'status',
                    'value' => 'sold'
                ],
                [
                    'key' => 'choose_who_you_are',
                    'value' => 'creator'
                ],
                [
                    'key' => 'collection',
                    'value' => '',
                    'compare' => '='
                ]
            ]
        ];
    }

    return get_posts($args);
}

/**
 * @param string $promo 'highlight' or 'boost'
 * @param int $amount -1 (all posts), 0, or positive int
 * @return array array IDs of posts
 */
function get_collections_only_promo(string $promo, int $amount = -1): array
{
    global $wpdb;

    $highlight_product = 713;
    $boost_product = 714;

    $highlighted = $wpdb->get_col(
        $wpdb->prepare("SELECT p.ID as postID 
            FROM {$wpdb->prefix}posts AS p
            LEFT JOIN {$wpdb->prefix}mepr_transactions AS txn ON (p.ID = txn.post_id)
            WHERE p.post_status = 'publish' AND p.post_type = 'collection' AND txn.product_id = {$highlight_product} AND (txn.status = 'complete' AND txn.expires_at >= %s)
            ORDER BY txn.created_at DESC",
            current_time('Y-m-d h:i:s')
        )
    );

    $boosted = $wpdb->get_col(
        $wpdb->prepare("SELECT p.ID as postID 
            FROM {$wpdb->prefix}posts AS p
            LEFT JOIN {$wpdb->prefix}mepr_transactions AS txn ON (p.ID = txn.post_id)
            WHERE p.post_status = 'publish' AND p.post_type = 'collection' AND txn.product_id = {$boost_product} AND (txn.status = 'complete' AND txn.expires_at >= %s)
            ORDER BY txn.created_at DESC",
            current_time('Y-m-d h:i:s')
        )
    );

    $needle_ids = [];
    $haystack_ids = [];

    if ($promo == 'highlight') {

        $needle_ids = $highlighted;
        $haystack_ids = $boosted;

    } elseif ($promo == 'boost') {

        $needle_ids = $boosted;
        $haystack_ids = $highlighted;
    }

    if (empty($haystack_ids)) {
        return $needle_ids;
    }

    $result = [];
    foreach ($needle_ids as $id) {
        if (!in_array($id, $haystack_ids)) {
            $result[] = $id;
        }
    }

    $amount = ($amount < 0) ? null : $amount;
    return array_slice($result, 0, $amount);
}

function handleErrorOnSavingParserData(WP_Error $error, string $temp_file): int
{
    global $post_ID;
    global $current_user;
    set_transient("save_post_errors_{$post_ID}_{$current_user->ID}", $error, 30);
    @unlink($temp_file);
    return 0;
}

add_action(
    'admin_notices',
    function () {
        global $post_ID;
        global $current_user;

        $transient = "save_post_errors_{$post_ID}_{$current_user->ID}";

        if ($error = get_transient($transient)) {
            $class = 'notice notice-warning';
            $message = $error->get_error_message();
            printf(
                '<div class="%1$s" style="background:#d94f4f;color:#fff;"><p>%2$s</p></div>',
                esc_attr($class),
                esc_html($message),
            );
            delete_transient($transient);
        }
    }
);

add_action(
    'save_post_artwork',
    function ($post_ID, $post, $update) {
        if ( ! $update) {
            return;
        }

        $postStatuses = ['draft', 'trash', 'untrash'];
        if (
            in_array($post->post_status, $postStatuses)
            ||
            (isset($_POST['post_view']) && 'list' == $_POST['post_view'])
        ) {
            return;
        }

        $status = $_POST['acf']['field_620f89463bfb6'] ?? '';
        if ('in_progress' == $status) {
            return;
        }

        // If this is a revision, get real post ID
        if ($realPostId = wp_is_post_revision($post_ID)) {
            $post_ID = $realPostId;
        }

        $linkMarketplace = $_POST['acf']['field_620f89d03bfb7'] ?? '';

        $name_this_function = 'updatePostWithParsingData';

        // Removing the hook to not get loop due to wp_update_post function call the same hook
        remove_action("save_post_artwork", $name_this_function, 10);

        // Inner this function, the save_post hook fires again by wp_update_post
        update_nft_by_parser($post_ID, $linkMarketplace);

        // Set up the hook back
        add_action("save_post_artwork", $name_this_function, 10, 3);

        clean_post_cache($post_ID); // required clear post cache in the end
    },
    10, 3,
);

function update_nft_by_parser(int $post_ID, string $linkMarketplace):int
{
    try {
        $resultParsing = ParserManager::parse($linkMarketplace);

        $title_post = $resultParsing->name;
        $price = (isset($resultParsing->price)) ? $resultParsing->price->price . ' ' . $resultParsing->price->currency : '';
        $description = ($resultParsing->description) ? substr($resultParsing->description, 0, 145) . '...' : '';
        // see the logic of getting id of attachment bellow
        $mediaId = getIdAttachedExternalMediaToPost($post_ID, $resultParsing->content, $resultParsing->content_mimetype);

    } catch (Exception $exc) {
        $error = new WP_Error('incorrect_marketplace_link', $exc->getMessage());
        return handleErrorOnSavingParserData($error, '');
    }

    wp_update_post([
        'ID' => $post_ID,
        'post_title' => $title_post,
    ]);

    update_post_meta($post_ID, 'price', $price);
    update_post_meta($post_ID, 'description', $description);

    if ($mediaId) {
        update_field('thumbnail', $mediaId, $post_ID);
    }

    return 1;
}

function getIdAttachedExternalMediaToPost(int $post_ID, string $mediaUrl, string $mimetype): int
{
    // upload file to temporary folder
    $temp_file = download_url($mediaUrl);
    if (is_wp_error($temp_file)) {
        return handleErrorOnSavingParserData($temp_file, '');
    }

    // checking if the same file already attached to the post and to not upload extra files in media library
    $currentFile = get_field('thumbnail', $post_ID);
    if (isset($currentFile['id'])) {
        if (hash_file('md5', $temp_file) === hash_file('md5', wp_get_original_image_path($currentFile['id']))) {
            @unlink($temp_file);
            return 0;
        }
    }

    // need file extension to finish the function wp_handle_upload() below without error.
    $fileExt = wp_get_default_extension_for_mime_type($mimetype) ?: '';

    // collect an array similar to global variable $_FILES in PHP
    $file = [
        'name' => basename($mediaUrl) . '.' . $fileExt,
        'type' => $mimetype,
        'tmp_name' => $temp_file,
        'error' => 0,
        'size' => filesize($temp_file),
    ];

    // media from 'opensea.io' coming without extension and WP throws a loading error by wp_check_filetype()
    $file_load = wp_handle_sideload($file, ['test_form' => false]);

    if (isset($file_load['error'])) {
        $message = 'Issue uploading the external media file: ' . $file_load['error'];
        $error = new WP_Error('failed_attach_external_media', $message);
        return handleErrorOnSavingParserData($error, $temp_file);
    }

    $filename = $file_load['file'];

    $attachment = [
        'post_mime_type' => $file_load['type'],
        'post_title' => preg_replace('/\.[^.]+$/', '', basename($filename)),
        'post_content' => '',
        'post_status' => 'inherit',
        'guid' => $file_load['url']
    ];

    $attachment_id = wp_insert_attachment($attachment, $filename);

    if (!$attachment_id) {
        $error = new WP_Error('failed_attach_external_media', __('Failed attaching external media file.'));
        return handleErrorOnSavingParserData($error, $temp_file);
    }

    require_once(ABSPATH . 'wp-admin/includes/image.php');
    $attachment_data = wp_generate_attachment_metadata($attachment_id, $filename);
    wp_update_attachment_metadata($attachment_id, $attachment_data);

    wp_delete_attachment($currentFile['id']);
    @unlink($temp_file);

    return $attachment_id;
}