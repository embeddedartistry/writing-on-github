<?php
/**
 * The post object which represents both the GitHub and WordPress post
 * @package WP_Wordpress_GitHub_Sync
 */

/**
 * Class Wordpress_GitHub_Sync_Post
 */
class Wordpress_GitHub_Sync_Post {

    /**
     * Api object
     *
     * @var Wordpress_GitHub_Sync_Api
     */
    public $api;

    /**
     * Post ID
     * @var integer
     */
    public $id = 0;

    /**
     * Blob object
     * @var Wordpress_GitHub_Sync_Blob
     */
    public $blob;

    /**
     * Post object
     * @var WP_Post
     */
    public $post;

    /**
     * Post args.
     *
     * @var array
     */
    protected $args;

    /**
     * Post meta.
     *
     * @var array
     */
    protected $meta;

    /**
     * Whether the post has been saved.
     *
     * @var bool
     */
    protected $new = true;


    protected $old_github_path;

    /**
     * Instantiates a new Post object
     *
     * @param int|array                 $id_or_args Either a post ID or an array of arguments.
     * @param Wordpress_GitHub_Sync_Api $api API object.
     *
     * @todo remove database operations from this method
     */
    public function __construct( $id_or_args, Wordpress_GitHub_Sync_Api $api ) {
        $this->api = $api;

        if ( is_numeric( $id_or_args ) ) {
            $this->id   = (int) $id_or_args;
            $this->post = get_post( $this->id );
            $this->new  = false;
        }

        if ( is_array( $id_or_args ) ) {
            $this->args = $id_or_args;

            if ( isset( $this->args['ID'] ) ) {
                $this->post = get_post( $this->args['ID'] );

                if ( $this->post ) {
                    $this->id  = $this->post->ID;
                    $this->new = false;
                } else {
                    unset( $this->args['ID'] );
                }
            }
        }
    }

    public function id() {
        return $this->id;
    }

    /**
     * Returns the post type
     */
    public function type() {
        return $this->post->post_type;
    }

    /**
     * Returns the post type
     */
    public function status() {
        return $this->post->post_status;
    }

    /**
     * Returns the post name
     */
    public function name() {
        return $this->post->post_name;
    }

    /**
     * Returns true if the post has a password
     * @return bool
     */
    public function has_password() {
        return ! empty( $this->post->post_password );
    }

    /**
     * Combines the 2 content parts for GitHub
     */
    public function github_content() {
        $use_blob = wghs_is_dont_export_content() && $this->blob;
        $content = $use_blob ?
            $this->blob->post_content() :
            $this->post_content();

        return $this->front_matter() . $content;
    }

    /**
     * The post's YAML frontmatter
     *
     * Returns String the YAML frontmatter, ready to be written to the file
     */
    public function front_matter() {
        return "---\n" . spyc_dump( $this->meta() ) . "---\n";
    }

    /**
     * Returns the post_content
     *
     * Markdownify's the content if applicable
     */
    public function post_content() {
        $content = $this->post->post_content;

        if ( function_exists( 'wpmarkdown_html_to_markdown' ) ) {
            $content = wpmarkdown_html_to_markdown( $content );
        } else if ( class_exists( 'WPCom_Markdown' ) ) {
            if ( WPCom_Markdown::get_instance()->is_markdown( $this->post->ID ) ) {
                $content = $this->post->post_content_filtered;
            }
        }

        return apply_filters( 'wghs_content_export', $content, $this );
    }

    public function old_github_path() {
        return $this->old_github_path;
    }

    public function set_old_github_path( $path ) {
        $this->old_github_path = $path;
        update_post_meta( $this->id, '_wghs_github_path', $path );
    }


    /**
     * Retrieves or calculates the proper GitHub path for a given post
     *
     * Returns (string) the path relative to repo root
     */
    public function github_path() {
        $path = $this->github_directory() . $this->github_filename();
        //error_log(sprintf(__('github directory: %s, github_filename: %s'), $this->github_directory(), $this->github_filename()));

        return $path;
    }

    /**
     * Get GitHub directory based on post
     *
     * @return string
     */
    public function github_directory() {

        $name = '';

        switch ( $this->type() ) {
            case 'post':
                $name = 'posts/' . get_the_time( 'Y', $this->id );
                break;
            case 'page':
                $name = 'pages';
                break;
            case 'glossary':
                $name = 'glossary';
                break;
            case 'fieldatlas':
            	$name = 'fieldatlas';
            	break;
            case 'newsletters':
                $name = 'newsletters/' . get_the_time( 'Y', $this->id );
            	break;
            case 'course':
            	// Course .md files are placed into the a subdirectory directory
            	// corresponding to the course name. Lessons/modules will also go here.
            	// We use get_the_title because get_name() can return different
            	// values than what is used for the lesson in some cases (e.g.,
            	// course was renamed), which causes two directories to appear
            	// for each course
            	$name = 'courses/' . sanitize_title(get_the_title($this->id));
            	break;
            case 'lesson':
				// Lessons need to be organized under courses/course_name/module_name/
                $course_id = Sensei()->lesson->get_course_id($this->id);
                $course_name = get_the_title($course_id);
				$name = 'courses/' . sanitize_title($course_name);
				$module = Sensei()->modules->get_lesson_module($this->id);
				if($module)
                {
                    // We want to remove author names from module names
                    // because Sensei is putting those in place for admin users
                    // which causes problems when importing or exporting post changes
                    $exploded_module_name = explode('(', $module->name);
                    $name = $name . '/' . sanitize_title($exploded_module_name[0]);
                }
                else
				{
                    error_log(sprintf(__('Module for lesson %s could not be grabbed!'), $this->get_name()));
				}

				break;
            default:
                $obj = get_post_type_object( $this->type() );

                if ( $obj ) {
                    $name = strtolower( $obj->labels->name );
                }

                if ( ! $name ) {
                    $name = '';
                }
        }

        if ( $name ) {
            $name = $name . '/';
        }

        return apply_filters( 'wghs_directory_published', $name, $this );
    }

    /**
     * Build GitHub filename
     */
    public function github_filename() {
        $filename = $this->get_name() . '.md';

        return apply_filters( 'wghs_filename', $filename, $this );
    }

    /**
     * Returns a post slug we can use in the GitHub filename
     *
     * @return string
     */
    protected function get_name() {
        if ( '' !== $this->name() ) {
            return $this->name();
        }

        return sanitize_title( get_the_title( $this->post ) );
    }

    /**
     * is put on github
     * @return boolean
     */
    public function is_on_github() {
        $sha = get_post_meta( $this->id, '_wghs_sha', true );
        $github_path = get_post_meta( $this->id, '_wghs_github_path', true );
        if ( $sha && $github_path ) {
            return true;
        }
        return false;
    }

    /**
     * Returns the URL for the post on GitHub.
     *
     * @return string
     */
    public function github_view_url() {
        $github_path = get_post_meta( $this->id, '_wghs_github_path', true );
        $repository = $this->api->fetch()->repository();
        $branch = $this->api->fetch()->branch();

        return "https://github.com/$repository/blob/$branch/$github_path";
    }

    /**
     * Returns the URL for the post on GitHub.
     *
     * @return string
     */
    public function github_edit_url() {
        $github_path = get_post_meta( $this->id, '_wghs_github_path', true );
        $repository = $this->api->fetch()->repository();
        $branch = $this->api->fetch()->branch();

        return "https://github.com/$repository/edit/$branch/$github_path";
    }

    /**
     * Determines the last author to modify the post
     *
     * Returns Array an array containing the author name and email
     */
    public function last_modified_author() {
        if ( $last_id = get_post_meta( $this->id, '_edit_last', true ) ) {
            $user = get_userdata( $last_id );

            if ( $user ) {
                return array( 'name' => $user->display_name, 'email' => $user->user_email );
            }
        }

        return array();
    }

    /**
     * The post's sha
     * Cached as post meta, or will make a live call if need be
     *
     * Returns String the sha1 hash
     */
    public function sha() {
        $sha = get_post_meta( $this->id, '_wghs_sha', true );

        // If we've done a full export and we have no sha
        // then we should try a live check to see if it exists.
        // if ( ! $sha && 'yes' === get_option( '_wghs_fully_exported' ) ) {

        //  // @todo could we eliminate this by calling down the full tree and searching it
        //  $data = $this->api->fetch()->remote_contents( $this );

        //  if ( ! is_wp_error( $data ) ) {
        //      update_post_meta( $this->id, '_wghs_sha', $data->sha );
        //      $sha = $data->sha;
        //  }
        // }

        // if the sha still doesn't exist, then it's empty
        if ( ! $sha || is_wp_error( $sha ) ) {
            $sha = '';
        }

        return $sha;
    }

    /**
     * Save the sha to post
     *
     * @param string $sha
     */
    public function set_sha( $sha ) {
        update_post_meta( $this->id, '_wghs_sha', $sha );
    }

    /**
     * The post's metadata
     *
     * Returns Array the post's metadata
     */
    public function meta() {
        $meta = array(
            'ID'           => $this->id,
            'post_title'   => get_the_title( $this->post ),
            'post_name'    => $this->post->post_name,
            'author'       => ( $author = get_userdata( $this->post->post_author ) ) ? $author->display_name : '',
            'post_date'    => $this->post->post_date,
            'post_excerpt' => $this->post->post_excerpt,
            'layout'       => get_post_type( $this->post ),
            'link'         => get_permalink( $this->post ),
            'published'    => 'publish' === $this->status() ? true : false,
            'tags'         => wp_get_post_tags( $this->id, array( 'fields' => 'names' ) ),
            'categories'   => wp_get_post_categories( $this->id, array( 'fields' => 'names' ) )
        );
        if ( empty($this->post->post_name) ) {
            unset($meta['post_name']);
        }
        if ( empty($this->post->post_excerpt) ) {
            unset($meta['post_excerpt']);
        }
        if ( 'yes' == get_option('wghs_ignore_author') ) {
            unset($meta['author']);
        }

        //convert traditional post_meta values, hide hidden values, skip already populated values
        // foreach ( get_post_custom( $this->id ) as $key => $value ) {

        //  if ( '_' === substr( $key, 0, 1 ) || isset( $meta[ $key ] ) ) {
        //      continue;
        //  }

        //  $meta[ $key ] = $value;

        // }

        return apply_filters( 'wghs_post_meta', $meta, $this );
    }

    /**
     * Returns whether the Post has been saved in the DB yet.
     *
     * @return bool
     */
    public function is_new() {
        return $this->new;
    }

    /**
     * Sets the Post's meta.
     *
     * @param array $meta
     */
    public function set_meta( $meta ) {
        $this->meta = $meta;
    }

    /**
     * Returns the Post's arguments.
     *
     * @return array
     */
    public function get_args() {
        return $this->args;
    }

    /**
     * Returns the Post's meta.
     *
     * @return array
     */
    public function get_meta() {
        return $this->meta;
    }

    /**
     * Get the blob
     * @return Wordpress_GitHub_Sync_Blob
     */
    public function get_blob() {
        return $this->blob;
    }

    /**
     * Set the blob
     * @param Wordpress_GitHub_Sync_Blob $blob
     */
    public function set_blob( Wordpress_GitHub_Sync_Blob $blob ) {
        $this->blob = $blob;
    }

    /**
     * Sets the Post's WP_Post object.
     *
     * @param WP_Post $post
     *
     * @return $this
     */
    public function set_post( WP_Post $post ) {
        $this->post = $post;
        $this->id   = $post->ID;

        return $this;
    }

    /**
     * Transforms the Post into a Blob.
     *
     * @return Wordpress_GitHub_Sync_Blob
     */
    public function to_blob() {
        $data = new stdClass;

        $data->path    = $this->github_path();
        $data->content = $this->github_content();
        $data->sha     = $this->sha();

        return new Wordpress_GitHub_Sync_Blob( $data );
    }
}
