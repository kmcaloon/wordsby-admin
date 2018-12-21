<?php 

$branch = defined('WORDSBY_GITLAB_BRANCH') ? WORDSBY_GITLAB_BRANCH : 'master';

// This file is generated by Composer
require_once __DIR__ . '/vendor/autoload.php';

function getGitlabToken() {
    // https://docs.gitlab.com/ee/user/profile/personal_access_tokens.html
    $gitlab_token = defined('WORDSBY_GITLAB_API_TOKEN') ? WORDSBY_GITLAB_API_TOKEN : false;
    
    if (!$gitlab_token) return;
    return $gitlab_token;
}

function getGitlabClient () {
    return \Gitlab\Client::create('https://gitlab.com/api/v4/')
        ->authenticate(getGitlabToken(), \Gitlab\Client::AUTH_URL_TOKEN);
}

function getTree($client, $base_path) {
    global $branch;
    $tree = $client->api('repositories')->tree(WORDSBY_GITLAB_PROJECT_ID, array(
        'path' => $base_path,
        'recursive' => true,
        'ref' => $branch,
        'per_page' => 9999999
    ));

    return $tree;
}

function isFileInRepo($client, $base_path, $filename) {
    $tree = getTree($client, $base_path);

    return in_array($filename, array_column($tree, 'name'));
}

function makeImagesRelative($json) {
    $url = preg_quote(get_site_url(), "/");

    return preg_replace(
        "/$url\/wp-content\//", '../', $json
    );
}


add_action('acf/save_post', 'commitData');

function commitData($id) {
    if (isset($_POST['nav-menu-data'])) return;

    if (!defined('WORDSBY_GITLAB_PROJECT_ID')) return $id;
    if (isset($_POST) && isset($_POST['wp-preview']) && $_POST['wp-preview'] === 'dopreview') return $id;

    global $branch;
    
    $site_url = get_site_url();
    $current_user = wp_get_current_user()->data;
    $username = $current_user->user_nicename;
    $title = get_the_title($id);
    $base_path = "wordsby/data/";
    
    $client = getGitlabClient();
    
    $collections_action = isFileInRepo($client, $base_path, 'collections.json') 
                                ? 'update' : 'create';

    $tax_terms_action = isFileInRepo($client, $base_path, 'tax-terms.json') 
                                ? 'update' : 'create';

    $options_action = isFileInRepo($client, $base_path, 'options.json') 
                                ? 'update' : 'create';

    $site_meta_action = isFileInRepo($client, $base_path, 'site-meta.json') 
                                ? 'update' : 'create';

    $collections = json_encode(
        posts_formatted_for_gatsby(false), JSON_UNESCAPED_SLASHES
    );

    $collections_content = makeImagesRelative($collections);
    
    $tax_terms_content = json_encode(
        custom_api_get_all_taxonomies_terms_callback(), 
        JSON_UNESCAPED_SLASHES
    );

    $options_content = makeImagesRelative(json_encode(
        custom_api_get_all_options_callback(),
        JSON_UNESCAPED_SLASHES
    ));

    

    $site_meta_content = json_encode(array(
        array(
            'key' => 'url',
            'value' => get_bloginfo('url')
        ),
        array(
            'key' => 'name',
            'value' => get_bloginfo('name')
        ),
        array(
            'key' => 'description',
            'value' => get_bloginfo('description')
        ),
    ));


    $commit = $client->api('repositories')->createCommit(WORDSBY_GITLAB_PROJECT_ID, array(
        'branch' => $branch, 
        'commit_message' => "Post \"$title\" updated [id:$id] — by $username (from $site_url)",
        'actions' => array(
            array(
                'action' => $collections_action,
                'file_path' => $base_path . "collections.json",
                'content' => $collections_content,
                'encoding' => 'text'
            ),
            array(
                'action' => $tax_terms_action,
                'file_path' => $base_path . "tax-terms.json",
                'content' => $tax_terms_content,
                'encoding' => 'text'
            ),
            array(
                'action' => $options_action,
                'file_path' => $base_path . "options.json",
                'content' => $options_content,
                'encoding' => 'text'
            ),
            array(
                'action' => $site_meta_action,
                'file_path' => $base_path . "site-meta.json",
                'content' => $site_meta_content,
                'encoding' => 'text'
            ),
        ),
        'author_email' => $username,
        'author_name' => $current_user->user_email
    ));

    return $commit; 

}

add_action('delete_attachment', 'deleteMedia');
function deleteMedia($id) {
    if (!defined('WORDSBY_GITLAB_PROJECT_ID')) return $id;

    global $branch;

    $site_url = get_site_url();
    $current_user = wp_get_current_user()->data;
    $username = $current_user->user_nicename;

    $filepath = wp_get_attachment_metadata($id)['file'];
    $filename = basename($filepath);
    $filedirectory = dirname($filepath); 

    $base_path = 'wordsby/uploads';

    $fulldirectory = "$base_path/$filedirectory/";
    $full_filepath = "$fulldirectory$filename";

    $client = getGitlabClient();

    $media_exists = isFileInRepo($client, $fulldirectory, $filename);

    if (!$media_exists) return;

    $commit = $client->api('repositories')->createCommit(WORDSBY_GITLAB_PROJECT_ID, array(
        'branch' => $branch, 
        'commit_message' => "\"$filename\" deleted — by $username (from $site_url)",
        'actions' => array(
            array(
                'action' => 'delete',
                'file_path' => $full_filepath
            )
        ),
        'author_email' => $username,
        'author_name' => $current_user->user_email
    ));
}

add_action('wp_handle_upload', 'commitMedia');

function commitMedia($upload) {
    if (!defined("WORDSBY_GITLAB_PROJECT_ID")) return $upload;

    global $branch;

    $initial_filepath = explode("uploads/",$upload['file'])[1];
    $filename = basename($initial_filepath);
    $subdir = dirname($initial_filepath);
    
    $base_path = 'wordsby/uploads';
    $file_dir = "$base_path/$subdir";
    $filepath = "$file_dir/$filename";

    $site_url = get_site_url();
    $current_user = wp_get_current_user()->data;
    $username = $current_user->user_nicename;

    $client = getGitlabClient();

    $media_exists = isFileInRepo($client, $file_dir, $filename);

    $action = $media_exists ? 'update' : 'create';

    $commit = $client->api('repositories')->createCommit(WORDSBY_GITLAB_PROJECT_ID, array(
        'branch' => $branch, 
        'commit_message' => "\"$filename\" — by $username (from $site_url)",
        'actions' => array(
            array(
                'action' => $action,
                'file_path' => $filepath,
                'content' => base64_encode(file_get_contents($upload['file'])),
                'encoding' => 'base64'
            )
        ),
        'author_email' => $username,
        'author_name' => $current_user->user_email
    ));

    return $upload;
}





/**
 * Returns all child nav_menu_items under a specific parent.
 *
 * @since   1.2.0
 * @param int   $parent_id      The parent nav_menu_item ID
 * @param array $nav_menu_items Navigation menu items
 * @param bool  $depth          Gives all children or direct children only
 * @return array	returns filtered array of nav_menu_items
 */
function wordlify_get_nav_menu_item_children( $parent_id, $nav_menu_items, $depth = true ) {

    $nav_menu_item_list = array();

    foreach ( (array) $nav_menu_items as $nav_menu_item ) :

        if ( $nav_menu_item->menu_item_parent == $parent_id ) :

            $nav_menu_item_list[] = wordlify_format_menu_item( $nav_menu_item, true, $nav_menu_items );

            if ( $depth ) {
                if ( $children = wordlify_get_nav_menu_item_children( $nav_menu_item->ID, $nav_menu_items ) ) {
                    $nav_menu_item_list = array_merge( $nav_menu_item_list, $children );
                }
            }

        endif;

    endforeach;

    return $nav_menu_item_list;
}


/**
 * Check if a collection of menu items contains an item that is the parent id of 'id'.
 *
 * @since  1.2.0
 * @param  array $items
 * @param  int $id
 * @return array
 */
function has_children( $items, $id ) {
    return array_filter( $items, function( $i ) use ( $id ) {
        return $i['parent'] == $id;
    } );
}


/**
 * Handle nested menu items.
 *
 * Given a flat array of menu items, split them into parent/child items
 * and recurse over them to return children nested in their parent.
 *
 * @since  1.2.0
 * @param  $menu_items
 * @param  $parent
 * @return array
 */
function wordlify_nested_menu_items( &$menu_items, $parent = null ) {

    $parents = array();
    $children = array();

    // Separate menu_items into parents & children.
    array_map( function( $i ) use ( $parent, &$children, &$parents ){
        if ( $i['id'] != $parent && $i['parent'] == $parent ) {
            $parents[] = $i;
        } else {
            $children[] = $i;
        }
    }, $menu_items );

    foreach ( $parents as &$parent ) {

        if ( has_children( $children, $parent['id'] ) ) {
            $parent['children'] = wordlify_nested_menu_items( $children, $parent['id'] );
        }
    }

    return $parents;
}


/**
 * Format a menu item for REST API consumption.
 *
 * @since  1.2.0
 * @param  object|array $menu_item  The menu item
 * @param  bool         $children   Get menu item children (default false)
 * @param  array        $menu       The menu the item belongs to (used when $children is set to true)
 * @return array	a formatted menu item for REST
 */
function wordlify_format_menu_item( $menu_item, $children = false, $menu = array() ) {

    $item = (array) $menu_item;

    $menu_item = array(
        'id'          => abs( $item['ID'] ),
        'wordpress_id'          => abs( $item['ID'] ),
        'order'       => (int) $item['menu_order'],
        'parent'      => abs( $item['menu_item_parent'] ),
        'title'       => $item['title'],
        'url'         => $item['url'],
        'attr'        => $item['attr_title'],
        'target'      => $item['target'],
        'classes'     => implode( ' ', $item['classes'] ),
        'xfn'         => $item['xfn'],
        'description' => $item['description'],
        'object_id'   => abs( $item['object_id'] ),
        'object'      => $item['object'],
        'object_slug' => get_post( $item['object_id'] )->post_name,
        'type'        => $item['type'],
        'type_label'  => $item['type_label'],
        'acf'         => get_fields($item['ID'] ) ?  get_fields($item['ID'] ) : null
    );

    if ( $children === true && ! empty( $menu ) ) {
        $menu_item['children'] = wordlify_get_nav_menu_item_children( $item['ID'], $menu );
    }

    return apply_filters( 'rest_menus_wordlify_format_menu_item', $menu_item );
}

/**
 * Get menus.
 *
 * @since  1.2.0
 * @return array All registered menus
 * borrowed from wp-api-menus plugin
 */
function wordlify_get_menus() {
    $wp_menus = wp_get_nav_menus();

    $i = 0;
    $rest_menus = array();
    foreach ( $wp_menus as $wp_menu ) :

        $menu = (array) $wp_menu;

        $id = $menu['term_id'];

        $rest_menus[ $i ]                = $menu;
        $rest_menus[ $i ]['ID']          = $id;
        $rest_menus[ $i ]['wordpress_id']          = $id;
        $rest_menus[ $i ]['name']        = $menu['name'];
        $rest_menus[ $i ]['slug']        = $menu['slug'];
        $rest_menus[ $i ]['description'] = $menu['description'];
        $rest_menus[ $i ]['count']       = $menu['count'];


        $wp_menu_items  = $id ? wp_get_nav_menu_items( $id ) : array();


        $rest_menu_items = array();
        foreach ( $wp_menu_items as $item_object ) {
            $rest_menu_items[] = wordlify_format_menu_item( $item_object );
        }

        $rest_menu_items = wordlify_nested_menu_items($rest_menu_items, 0);
        $rest_menus[ $i ]['items']       = $rest_menu_items;


        $i ++;
    endforeach;

    return $rest_menus;
}

add_action('wp_update_nav_menu', 'commitMenus');
function commitMenus($id) {
    if (!defined('WORDSBY_GITLAB_PROJECT_ID')) return $id;

    global $branch;

    
    $site_url = get_site_url();
    $current_user = wp_get_current_user()->data;
    $username = $current_user->user_nicename;
    $menu_object = wp_get_nav_menu_object($id);
    $title = $menu_object->name;
    
    $base_path = "wordsby/data/";
    
    $client = getGitlabClient();

    
    $menus_action = isFileInRepo($client, $base_path, 'menus.json') 
    ? 'update' : 'create';

    $menus = json_encode(
        wordlify_get_menus(), JSON_UNESCAPED_SLASHES
    );

    $url = preg_quote(get_site_url(), "/");

    $menus_content = preg_replace(
        "/$url/", '', makeImagesRelative($menus)
    );

    $commit = $client->api('repositories')->createCommit(WORDSBY_GITLAB_PROJECT_ID, array(
        'branch' => $branch, 
        'commit_message' => "Menu $menus_action \"$title\" [id:$id] — by $username (from $site_url)",
        'actions' => array(
            array(
                'action' => $menus_action,
                'file_path' => $base_path . "menus.json",
                'content' => $menus_content,
                'encoding' => 'text'
            )
        ),
        'author_email' => $username,
        'author_name' => $current_user->user_email
    ));

    return $commit; 

}

?>