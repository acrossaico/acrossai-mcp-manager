<?php
/**
 * Orchestrator for the absorbed acrossai-core-abilities runtime.
 *
 * Wired from Main.php::define_public_hooks(). Two public entry points:
 * - register_category_callbacks( AcrossAI_Loader $loader ): adds 17 loader
 *   actions on wp_abilities_api_categories_init, one per Category_Registrar.
 * - register_abilities(): instantiates the 176 absorbed ability classes and
 *   runs the three companion-Main.php extras (Cron_Helpers::register_filter,
 *   Upload_Media chunk-sweep cron). Wired to plugins_loaded @ P20 matching
 *   the companion's original hook point.
 *
 * @license    GPL-2.0-or-later
 * @package    AcrossAI_Abilities_Manager
 * @subpackage Includes\Abilities
 * @since      0.1.0
 */

namespace AcrossAI_Abilities_Manager\Includes\Abilities;

use AcrossAI_Abilities_Manager\Includes\AcrossAI_Loader;
use AcrossAI_Abilities_Manager\Includes\Abilities\Utilities\Cron_Helpers;
use AcrossAI_Abilities_Manager\Includes\Modules\Library\AcrossAI_Integration_Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Bootstrap singleton for the absorbed ability inventory (Feature 046).
 */
final class AcrossAI_Core_Abilities_Bootstrap {

	/**
	 * Singleton reference.
	 *
	 * @var self|null
	 */
	protected static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor — access via instance().
	 */
	private function __construct() {}

	/**
	 * Register the 17 category-registrar callbacks with the manager Loader.
	 *
	 * Called from Main.php::define_public_hooks() so every add_action
	 * literally traces back to Main.php (AC-HOOKS-MAIN literalism).
	 *
	 * @param AcrossAI_Loader $loader Manager hook loader.
	 * @return void
	 */
	public function register_category_callbacks( AcrossAI_Loader $loader ): void {
		$loader->add_action( 'wp_abilities_api_categories_init', Plugins\Category_Registrar::instance(), 'register' );
		$loader->add_action( 'wp_abilities_api_categories_init', Themes\Category_Registrar::instance(), 'register' );
		$loader->add_action( 'wp_abilities_api_categories_init', FileManager\Category_Registrar::instance(), 'register' );
		$loader->add_action( 'wp_abilities_api_categories_init', Cache\Category_Registrar::instance(), 'register' );
		$loader->add_action( 'wp_abilities_api_categories_init', Database\Category_Registrar::instance(), 'register' );
		$loader->add_action( 'wp_abilities_api_categories_init', Users\Category_Registrar::instance(), 'register' );
		$loader->add_action( 'wp_abilities_api_categories_init', Block\Category_Registrar::instance(), 'register' );
		// Feature 067 — Elementor category (self-guards on class_exists inside register()).
		$loader->add_action( 'wp_abilities_api_categories_init', Elementor\Category_Registrar::instance(), 'register' );
		// Feature 069 — Rank Math category (self-guards on class_exists inside register()).
		$loader->add_action( 'wp_abilities_api_categories_init', RankMath\Category_Registrar::instance(), 'register' );
		$loader->add_action( 'wp_abilities_api_categories_init', SiteKit\Category_Registrar::instance(), 'register' );

		// Feature 103 — Contact Form 7 category (self-guards on class_exists inside register()).
		$loader->add_action( 'wp_abilities_api_categories_init', ContactForm7\Category_Registrar::instance(), 'register' );
		// Feature 107 — Classic Editor category (self-guards on the plugin inside register()).
		$loader->add_action( 'wp_abilities_api_categories_init', ClassicEditor\Category_Registrar::instance(), 'register' );
		// Feature 109 — The Events Calendar category (self-guards on the plugin inside register()).
		$loader->add_action( 'wp_abilities_api_categories_init', EventsCalendar\Category_Registrar::instance(), 'register' );
		// Feature 110 — Event Tickets category (self-guards on the plugin inside register()).
		$loader->add_action( 'wp_abilities_api_categories_init', EventTickets\Category_Registrar::instance(), 'register' );

		// Feature 112 — WPCode category (self-guards on the plugin inside register()).
		$loader->add_action( 'wp_abilities_api_categories_init', WPCode\Category_Registrar::instance(), 'register' );

		// Feature 116 — Loco Translate category (self-guards on the plugin inside register()).
		$loader->add_action( 'wp_abilities_api_categories_init', LocoTranslate\Category_Registrar::instance(), 'register' );

		// Feature 118 — cookie consent category (self-guards on the plugin inside register()).
		$loader->add_action( 'wp_abilities_api_categories_init', Consent\Category_Registrar::instance(), 'register' );

		// Feature 119 — email delivery category (self-guards on the plugin inside register()).
		$loader->add_action( 'wp_abilities_api_categories_init', Email\Category_Registrar::instance(), 'register' );

		// Feature 121 — store category (self-guards on WooCommerce inside register()).
		$loader->add_action( 'wp_abilities_api_categories_init', Store\Category_Registrar::instance(), 'register' );
		$loader->add_action( 'wp_abilities_api_categories_init', UpdraftPlus\Category_Registrar::instance(), 'register' );
		$loader->add_action( 'wp_abilities_api_categories_init', AllInOne\Category_Registrar::instance(), 'register' );

		// Feature 104 — LiteSpeed Cache category (self-guards on the host probe inside register()).
		$loader->add_action( 'wp_abilities_api_categories_init', LiteSpeed\Category_Registrar::instance(), 'register' );

		// Feature 105 — ACF category (self-guards on the host probe inside register()).
		$loader->add_action( 'wp_abilities_api_categories_init', Acf\Category_Registrar::instance(), 'register' );

		// Feature 106 — Yoast SEO category (self-guards on the host probe inside register()).
		$loader->add_action( 'wp_abilities_api_categories_init', Yoast\Category_Registrar::instance(), 'register' );
		// Feature 132 — All in One SEO category. Registered unconditionally, matching the 0.0.44
		// backup-suite fix: a guarded registrar in front of a gated registration is how a whole
		// suite gets dropped with only a _doing_it_wrong notice to show for it.
		$loader->add_action( 'wp_abilities_api_categories_init', Aioseo\Category_Registrar::instance(), 'register' );
		$loader->add_action( 'wp_abilities_api_categories_init', Settings\Category_Registrar::instance(), 'register' );
		$loader->add_action( 'wp_abilities_api_categories_init', Fonts\Category_Registrar::instance(), 'register' );
		$loader->add_action( 'wp_abilities_api_categories_init', Content\Category_Registrar::instance(), 'register' );
		$loader->add_action( 'wp_abilities_api_categories_init', Taxonomies\Category_Registrar::instance(), 'register' );
		$loader->add_action( 'wp_abilities_api_categories_init', Media\Category_Registrar::instance(), 'register' );
		$loader->add_action( 'wp_abilities_api_categories_init', Comments\Category_Registrar::instance(), 'register' );
		$loader->add_action( 'wp_abilities_api_categories_init', Menus\Category_Registrar::instance(), 'register' );
		$loader->add_action( 'wp_abilities_api_categories_init', Options\Category_Registrar::instance(), 'register' );
		$loader->add_action( 'wp_abilities_api_categories_init', Cron\Category_Registrar::instance(), 'register' );
		$loader->add_action( 'wp_abilities_api_categories_init', SiteHealth\Category_Registrar::instance(), 'register' );
		$loader->add_action( 'wp_abilities_api_categories_init', Core\Category_Registrar::instance(), 'register' );
		// Feature 055 — two new categories.
		$loader->add_action( 'wp_abilities_api_categories_init', AdminMenu\Category_Registrar::instance(), 'register' );
		$loader->add_action( 'wp_abilities_api_categories_init', ContentSearch\Category_Registrar::instance(), 'register' );
		// Feature 059 — Recovery Mode / fatal-error abilities.
		$loader->add_action( 'wp_abilities_api_categories_init', Recovery\Category_Registrar::instance(), 'register' );
		// Feature 063 — Widgets category.
		$loader->add_action( 'wp_abilities_api_categories_init', Widgets\Category_Registrar::instance(), 'register' );
		// Feature 100 — the category every Toolset dispatcher declares. Kept
		// distinct from any category holding real abilities: sharing one would
		// mean an operator's setting for that category applied to the Toolset
		// itself. See DEC-ABILITY-GROUP-IDENTIFIER-LOAD-BEARING.
		$loader->add_action( 'wp_abilities_api_categories_init', Toolset\Category_Registrar::instance(), 'register' );
		// Feature 061 — Debugging category. Its registrar shipped with the
		// feature but was never wired here, so the category never existed and
		// WP rejected all seven Debugging abilities on every request with
		// _doing_it_wrong. Registering the abilities is not enough; a category
		// must be registered before any ability may claim it.
		$loader->add_action( 'wp_abilities_api_categories_init', Debugging\Category_Registrar::instance(), 'register' );
		// Feature 130 — Redirection category. Guarded on the plugin so it is
		// absent on sites without Redirection.
		$loader->add_action( 'wp_abilities_api_categories_init', Redirection\Category_Registrar::instance(), 'register' );
		// Feature 136 — Versions category. Registered UNCONDITIONALLY: the abilities that claim it
		// are registered whether or not WP Rollback is installed, because on a site without it they
		// are the thing that says so. A category guarded on the host plugin while the abilities are
		// not is exactly what silently dropped all 17 backup abilities (#258) — WordPress rejects an
		// ability whose category does not exist, with no signal anyone sees.
		$loader->add_action( 'wp_abilities_api_categories_init', Versions\Category_Registrar::instance(), 'register' );
	}

	/**
	 * Instantiate the 176 absorbed ability classes so their inherited
	 * Ability_Definition constructor hooks acrossai_abilities_api_init.
	 *
	 * Also runs the three extras the companion Main.php ran alongside the
	 * ability instantiations: Cron_Helpers filter registration, the
	 * Upload_Media chunk-sweep hook, and the chunk-sweep cron scheduler.
	 *
	 * @return void
	 */
	public function register_abilities(): void {
		// Default class_exists() autoloading is REQUIRED here — the composer
		// autoloader hasn't necessarily resolved Ability_Definition yet at
		// plugins_loaded @ P20. Passing false as the second arg would skip
		// autoload and cause a silent no-op (Feature 046 regression bug found
		// via the live Library page showing "No abilities registered yet"
		// while all 176 classes were on disk).
		if ( ! class_exists( '\AcrossAI_Abilities_Manager\Includes\Modules\Library\Ability_Definition' ) ) {
			return;
		}

		new Plugins\Activate_Plugin();
		new Plugins\Deactivate_Plugin();
		new Plugins\Install_Plugin();
		new Plugins\Update_Plugin();
		new Plugins\List_Plugins();
		new Plugins\Check_Plugin_Updates();
		// Feature 064 — plugin lifecycle & integrity (3).
		new Plugins\Search_Wp_Plugin_Directory();
		new Plugins\Uninstall_Plugin();
		new Plugins\Verify_Plugin_Checksums();
		// The repair for what Verify_Plugin_Checksums reports: until now nothing could put
		// mismatched files back. Ungated and core-only — no host plugin involved.
		new Plugins\Reinstall_Plugin();
		new Settings\Get_Permalink_Structure();
		new Settings\Set_Permalink_Structure();
		new Settings\Flush_Permalink_Structure();
		new Settings\Get_Site_Title();
		new Settings\Update_Site_Title();
		new Settings\Get_Tagline();
		new Settings\Update_Tagline();
		new Settings\Update_Site_Logo();
		new Settings\Get_Site_Icon();
		new Settings\Update_Site_Icon();
		// Feature 063 — Rewrite rules read.
		new Settings\List_Rewrite_Rules();
		new Themes\Activate_Theme();
		new Themes\Delete_Theme();
		new Themes\Install_Theme();
		new Themes\Update_Theme();
		new Themes\Reinstall_Theme();
		new Themes\List_Themes();
		new Users\Get_User();
		new Users\List_Users();
		new Users\Create_User();
		new Users\Update_User();
		new Users\Delete_User();
		new Users\Reset_User_Password();
		new Users\List_User_Roles();
		new Users\Get_Role_Capabilities();
		// Feature 062 — role & capability CRUD.
		new Users\Add_Role_Capability();
		new Users\Remove_Role_Capability();
		new Users\Create_Role();
		new Users\Delete_Role();
		new Users\Reset_Role();
		new Users\Add_User_Capability();
		new Users\Remove_User_Capability();
		new Cache\Flush_Object_Cache();
		new Cache\Flush_Transients();
		new Cache\Flush_Rewrite_Rules();
		// Feature 064 — transient CRUD (4).
		new Cache\Get_Transient();
		new Cache\List_Transients();
		new Cache\Delete_Transient();
		new Cache\Delete_Expired_Transients();
		new Database\Extract_Db_Schema();
		new Database\Run_Db_Select_Query();
		new Database\Insert_Db_Row();
		new Database\Update_Db_Rows();
		new Database\Delete_Db_Rows();
		new Database\List_Db_Tables();
		new Database\Explain_Db_Query();
		new Database\Get_Db_Stats();
		new Database\Optimize_Db_Tables();
		// Feature 062 — serialized-safe site-wide search-replace.
		new Database\Search_Replace();
		// Feature 063 — Database introspection.
		new Database\Get_Db_Prefix();
		// Feature 086 — database health audits + safe writes.
		new Database\Audit_Health();
		new Database\Audit_Index_Health();
		new Database\Audit_Options_Health();
		new Database\Cleanup_Expired_Transients();
		new Database\Set_Option_Autoload();
		// Feature 087 — core-table engine audit + InnoDB conversion.
		new Database\Audit_Core_Table_Engines();
		new Database\Convert_Core_Tables_To_Innodb();
		// Feature 091: every FileManager ability below (except the three
		// deferred zip abilities marked with TODO(feature-092) at the top of
		// their source files) routes filesystem I/O through WP_Filesystem via
		// the Utilities\Wp_Filesystem_Init helper.
		new FileManager\Read_File();
		new FileManager\Create_File();
		new FileManager\Edit_File();
		new FileManager\Delete_File();
		// Feature 089 — consolidated file surface (list-directory + copy + move).
		new FileManager\List_Directory();
		new FileManager\Copy_File();
		new FileManager\Move_File();
		// Feature 090 — directory + append + file-info.
		new FileManager\Append_File();
		new FileManager\Create_Directory();
		new FileManager\Delete_Directory();
		new FileManager\File_Info();
		new FileManager\Read_Wp_Config();
		new FileManager\Edit_Wp_Config();
		new FileManager\Read_Debug_Log();
		new FileManager\Clear_Debug_Log();
		new FileManager\Create_Zip_Backup();
		new FileManager\Upload_Zip_Backup();
		new FileManager\Extract_Zip_Backup();
		new FileManager\Download_Zip_Backup();
		new FileManager\List_Zip_Backups();
		new FileManager\Delete_Zip_Backup();
		// Feature 063 — wp-config constant read.
		new FileManager\Get_Wp_Config_Constant();
		// Feature 094 — audit log reader.
		new FileManager\Get_Changelog();
		new Block\List_Block_Patterns();
		new Block\Read_Block_Pattern();
		new Block\Create_Block_Pattern();
		new Block\Update_Block_Pattern();
		new Block\Delete_Block_Pattern();
		new Block\List_Block_Templates();
		new Block\Read_Block_Template();
		new Block\Create_Block_Template();
		new Block\Update_Block_Template();
		new Block\Delete_Block_Template();
		new Block\List_Global_Styles();
		new Block\Read_Global_Style();
		new Block\Create_Global_Style();
		new Block\Update_Global_Style();
		new Block\Delete_Global_Style();
		new Block\Read_Theme_Json();
		new Block\Update_Theme_Json();
		new Block\List_Block_Style_Variations();
		new Block\Read_Block_Style_Variation();
		new Block\Create_Block_Style_Variation();
		new Block\Update_Block_Style_Variation();
		new Block\Delete_Block_Style_Variation();
		new Block\List_Blocks();
		new Block\Read_Block();
		new Block\List_Block_Template_Parts();
		new Block\Read_Block_Template_Part();
		new Block\Create_Block_Template_Part();
		new Block\Update_Block_Template_Part();
		new Block\Delete_Block_Template_Part();
		new Fonts\List_Font_Families();
		new Fonts\Get_Font_Family();
		new Fonts\Create_Font_Family();
		new Fonts\Delete_Font_Family();
		new Fonts\List_Font_Faces();
		new Fonts\Get_Font_Face();
		new Fonts\Create_Font_Face();
		new Fonts\Delete_Font_Face();
		new Content\Create_Post();
		new Content\Get_Post();
		new Content\Inspect_Post_Builder();
		new Content\Inspect_Post_Type();
		new Content\List_Post_Revisions();
		new Content\List_Posts();
		new Content\Update_Post();
		new Content\Delete_Post();
		new Content\Get_Post_Meta();
		new Content\Update_Post_Meta();
		new Content\Delete_Post_Meta();
		// Feature 064 — post-meta append (1).
		new Content\Add_Post_Meta();
		new Content\Create_Page();
		new Content\Get_Page();
		new Content\List_Page_Revisions();
		new Content\List_Pages();
		new Content\Update_Page();
		new Content\List_Post_Types();
		new Content\Create_Cpt_Item();
		new Content\Get_Cpt_Item();
		new Content\List_Cpt_Item_Revisions();
		new Content\List_Cpt_Items();
		new Content\Update_Cpt_Item();
		new Content\Delete_Cpt_Item();
		new Content\List_Post_Translations();
		new Content\Set_Post_Language();
		new Content\Link_Post_Translation();
		new Content\List_Jet_Engine_Options_Pages();
		new Content\Get_Jet_Engine_Options_Page();
		new Content\Update_Jet_Engine_Options_Page_Field();
		new Taxonomies\List_Taxonomies();
		new Taxonomies\Get_Taxonomy();
		new Taxonomies\List_Cpt_Taxonomies();
		new Taxonomies\List_Terms();
		new Taxonomies\Get_Term();
		new Taxonomies\Create_Term();
		new Taxonomies\Update_Term();
		new Taxonomies\Delete_Term();
		new Taxonomies\Assign_Cpt_Terms();
		new Media\Upload_Media();
		// Feature 131 — generate an image via WordPress 7.0's bundled AI client and
		// sideload it. Self-guards on the client + an image-capable connector at
		// execute time, so it is registered everywhere and reports when it cannot run.
		new Media\Generate_Image();
		new Media\Get_Media();
		new Media\List_Media();
		new Media\Update_Media();
		new Media\Delete_Media();
		new Media\Get_Media_Meta();
		new Media\Update_Media_Meta();
		new Media\List_Upload_Mime_Types();
		new Media\Update_Upload_Mime_Types();
		new Comments\Create_Comment();
		new Comments\Get_Comment();
		new Comments\List_Comments();
		new Comments\Update_Comment();
		new Comments\Delete_Comment();
		new Comments\Approve_Comment();
		new Comments\Unapprove_Comment();
		new Comments\Mark_As_Spam();
		new Comments\Get_Comment_Meta();
		new Comments\Update_Comment_Meta();
		new Menus\List_Menus();
		new Menus\Get_Menu();
		new Menus\Create_Menu();
		new Menus\Update_Menu();
		new Menus\Delete_Menu();
		new Menus\List_Menu_Items();
		new Menus\Get_Menu_Item();
		new Menus\Create_Menu_Item();
		new Menus\Update_Menu_Item();
		new Menus\Delete_Menu_Item();
		// Feature 063 — Widgets category (2 abilities).
		new Widgets\List_Widgets();
		new Widgets\Add_Widget();
		new Widgets\Deactivate_Widget();
		new Widgets\Get_Widget();
		new Widgets\Get_Widget_Management_Status();
		new Widgets\List_Widget_Types();
		new Widgets\Move_Widget();
		new Widgets\Remove_Widget();
		new Widgets\Reorder_Sidebar();
		new Widgets\Update_Widget();
		new Widgets\List_Sidebars();
		new Options\Get_Option();
		new Options\Update_Option();
		new Options\Delete_Option();
		new Options\List_Options();
		new Options\Search_Options();
		// Feature 064 — nested option access (2).
		new Options\Get_Nested_Option_Value();
		new Options\Patch_Option_Value();
		new Cron\List_Cron_Jobs();
		new Cron\Get_Cron_Job();
		new Cron\Get_Next_Cron_Run();
		new Cron\Check_Cron_Job_Exists();
		new Cron\List_Cron_Schedules();
		new Cron\Get_Cron_Schedule();
		new Cron\Get_Cron_Status();
		new Cron\List_Overdue_Cron_Jobs();
		new Cron\Create_Cron_Job();
		new Cron\Update_Cron_Job();
		new Cron\Run_Cron_Job_Now();
		new Cron\Create_Cron_Schedule();
		new Cron\Delete_Cron_Job();
		new Cron\Delete_Cron_Jobs_By_Hook();
		new Cron\Delete_Cron_Schedule();
		// Feature 063 — Cron endpoint reachability probe.
		new Cron\Test_Wp_Cron();
		new SiteHealth\Get_Site_Health_Status();
		new SiteHealth\Get_Site_Health_Info();
		new Core\Check_Wp_Core_Update();
		new Core\Update_Wp_Core();
		new Core\Rollback_Wp_Core();
		new Core\Reinstall_Wp_Core();
		// Feature 063 — Core introspection.
		new Core\Get_Wp_Version();
		// Feature 064 — core integrity (1).
		new Core\Verify_Core_Checksums();

		// Feature 055 — 31 new abilities across 10 domains.
		new Users\Get_Current_User_Access();
		new Taxonomies\Set_Term_Image();
		new Comments\Bulk_Update_Comments();
		// Feature 063 — Comment counts read.
		new Comments\Get_Comment_Count();
		new Media\Rename_Media_File();
		// Feature 063 — Image sizes read.
		new Media\List_Image_Sizes();
		new Menus\Get_Navigation_Context();
		new Menus\List_Navigation_Locations();
		new Block\Update_Post_Block();
		// Feature 066 — Block tree mutation & nested editing.
		new Block\Get_Post_Blocks();
		new Block\Outline_Post_Blocks();
		new Block\Add_Block();
		new Block\Remove_Block();
		new Block\Duplicate_Block();
		new Block\Move_Block();
		new Block\Insert_Pattern();
		new Content\Inspect_Post_Autosaves();
		new Block\Get_Site_Editor_Context();
		new Block\Refresh_Site_Editor_Context();
		new Block\List_Reusable_Blocks();
		new Block\List_Block_Areas();
		// Feature 070 — block-editor site-context reads.
		new Block\Get_Style_Guide();
		new Block\Get_Style_Book();
		new Block\Get_Site_Editor_Summary();
		new Block\Get_Site_Editor_References();
		new Block\List_Block_Categories();
		// Feature 071 — reusable-block writes & wp_navigation entities.
		new Block\Read_Reusable_Block();
		new Block\Create_Reusable_Block();
		new Block\Update_Reusable_Block();
		new Block\Extract_Reusable_Block();
		new Block\Insert_Reusable_Block_Into_Post();
		new Block\List_Navigations();
		new Block\Read_Navigation();
		new Block\Create_Navigation();
		new Block\Update_Navigation();
		// Feature 072 — usage lookups & advanced block-tree mutation.
		new Block\Find_Navigation_Usage();
		new Block\Find_Template_Part_Usage();
		new Block\Find_Reusable_Block_Usage();
		new Block\Mutate_Block_Tree();
		new Block\Transform_Blocks();
		new Block\Replace_Block_Text();
		new Block\Normalize_Heading_Levels();
		// Feature 073 — block/template locking + block bindings.
		new Block\Set_Block_Lock();
		new Block\Set_Allowed_Blocks();
		new Block\Set_Template_Lock();
		new Block\Read_Block_Bindings();
		new Block\Set_Block_Bindings();
		// Feature 074 — parse / serialize primitives + content-quality analysis.
		new Block\Parse_Content();
		new Block\Serialize_Blocks();
		new Block\Validate_Content();
		new Block\Audit_Content();
		new Block\Analyze_Content();
		new Block\Evaluate_Design();
		new Block\Suggest_Design_Fixes();
		new Block\Evaluate_Copy();
		new Block\Suggest_Copy_Fixes();
		new Block\Evaluate_Render_Context();
		// Feature 075 — block-editor generation, recipes & authoring guidance.
		new Block\Get_Block_Guidance();
		new Block\List_Page_Recipes();
		new Block\List_Section_Recipes();
		new Block\List_Query_Section_Recipes();
		new Block\Generate_Landing_Page();
		new Block\Generate_Section();
		new Block\Generate_Query_Section();
		new Block\Create_Page_From_Blocks();
		new Block\Create_Page_From_Pattern();
		new Block\Create_Landing_Page();
		new SiteHealth\Get_Site_Maintenance_Report();
		// Feature 063 — Maintenance-mode status read.
		new SiteHealth\Get_Maintenance_Mode_Status();
		// Site maintenance-mode toggle (write) — native WP-core .maintenance marker.
		new SiteHealth\Set_Site_Maintenance_Mode();
		new SiteHealth\Unset_Site_Maintenance_Mode();
		new Plugins\Get_Plugin_Lifecycle_Context();
		new Themes\Get_Theme_Lifecycle_Context();
		// Feature 063 — Theme mods introspection.
		new Themes\List_Theme_Mods();
		new AdminMenu\Get_Admin_Menu_Context();
		new AdminMenu\Refresh_Admin_Menu_Context();
		new AdminMenu\List_Admin_Menu_Pages();
		new AdminMenu\Get_Admin_Menu_Navigation_Target();
		new AdminMenu\List_Admin_Settings();
		new ContentSearch\Refresh_Content_Index_Batch();
		new ContentSearch\Search_Content_Items();
		new ContentSearch\Search_Content_Chunks();
		new ContentSearch\Find_Related_Content();
		new ContentSearch\Find_Internal_Links();
		new ContentSearch\Get_Internal_Link_Policy();
		new ContentSearch\Create_Internal_Link_Suggestions();
		new ContentSearch\List_Internal_Link_Suggestions();
		new ContentSearch\Review_Internal_Link_Suggestion();
		new ContentSearch\Apply_Internal_Link_Suggestion();
		new ContentSearch\Audit_Internal_Links();

		// Feature 059 — Recovery Mode / fatal-error abilities.
		new Recovery\Get_Recovery_Mode_Status();
		new Recovery\List_Paused_Plugins();
		new Recovery\List_Paused_Themes();
		new Recovery\List_Ability_Collisions();
		// Issue #255 — the sibling report: names registered abilities that no Toolset claims, which
		// is how an integrated plugin's own abilities go missing. Registered here rather than in a
		// folder of its own so it shares the Recovery category; see its class docblock.
		new Recovery\List_Unclassified_Abilities();
		new Recovery\Get_Recovery_Exit_Url();
		new Recovery\Unpause_Plugin();
		new Recovery\Unpause_Theme();
		new Recovery\List_Recent_Fatal_Errors();

		// Feature 061 — Debugging / Conflict Testing abilities.
		new Debugging\List_Plugins();
		new Debugging\Get_Overrides();
		new Debugging\Set_Override();
		new Debugging\Clear_Overrides();
		new Debugging\Deploy_Mu_Plugin();
		new Debugging\Remove_Mu_Plugin();
		new Debugging\Bulk_Set_Overrides();

		// Feature 129 — Query Monitor abilities. Self-guard on the QM plugin at
		// execute time, so they are registered whether or not Query Monitor is
		// installed — on a site without it they are the thing that says so. Gated
		// only on the integration switch (Feature 130): an operator who turned the
		// Query Monitor integration off has said they do not want it. The loopback
		// profiler's dispatcher is registered unconditionally so it is present on
		// the flagged loopback request even when the abilities are later gated.
		if ( $this->integration_on( 'query-monitor' ) ) {
			$this->register_query_monitor_abilities();
		}
		Utilities\QueryMonitor\Url_Profiler::register();

		// Feature 136 — version targeting. Self-guarded at execute time, so they are registered
		// whether or not WP Rollback is installed; listing published versions needs only
		// WordPress.org and works either way. Gated on the integration switch, like Query Monitor.
		if ( $this->integration_on( 'wp-rollback' ) ) {
			$this->register_version_abilities();
		}

		if ( $this->integration_on( 'wp-downgrade' ) ) {
			$this->register_core_pin_abilities();
		}

		// Feature 055 — register the option-backed lifecycle event log.
		Utilities\Lifecycle_Event_Log::register_hooks();

		// Extras the companion Main.php also ran alongside the ability
		// instantiations. See docs/planning/046-absorb-core-abilities-into-manager.md
		// CHANGE-4c.
		Cron_Helpers::register_filter();
		add_action( Media\Upload_Media::CHUNK_SWEEP_HOOK, array( Media\Upload_Media::class, 'sweep_chunk_sessions' ) );
		Media\Upload_Media::register_sweep_cron();

		// Feature 041: Upload_Zip_Backup chunk sweeper — same shape as Upload_Media.
		add_action( FileManager\Upload_Zip_Backup::CHUNK_SWEEP_HOOK, array( FileManager\Upload_Zip_Backup::class, 'sweep_chunk_sessions' ) );
		FileManager\Upload_Zip_Backup::register_sweep_cron();

		// Site maintenance-mode toggle — 5-min cron schedule + refresh callback.
		add_filter( 'cron_schedules', array( SiteHealth\Set_Site_Maintenance_Mode::class, 'register_cron_schedule' ) );
		add_action( SiteHealth\Set_Site_Maintenance_Mode::CRON_HOOK, array( SiteHealth\Set_Site_Maintenance_Mode::class, 'refresh_marker' ) );

		// Feature 067 — Elementor ability suite (up to 88 abilities under
		// elementor/*). Gated on Elementor presence; the Pro-only
		// subset (Custom Code CRUD + Form Submissions) is additionally gated
		// on Elementor Pro. See specs/067-elementor-abilities/plan.md.
		if ( class_exists( '\Elementor\Plugin' ) && $this->integration_on( 'elementor' ) ) {
			$this->register_elementor_free_abilities();
			if ( class_exists( '\ElementorPro\Plugin' ) || defined( 'ELEMENTOR_PRO_VERSION' ) ) {
				$this->register_elementor_pro_abilities();
			}

			// Feature 122 — tell Elementor's own MCP server that this site's Toolsets exist, so a
			// client connected to /wp-json/elementor/mcp can reach the rest of the site instead of
			// seeing Elementor's twenty tools and no sign anything else is here.
			//
			// Only the host declaration is gated here. It belongs in this block because the block
			// already checks both conditions that matter — Elementor present, and the Integrations
			// row not switched off — so switching Elementor off at
			// admin.php?page=acrossai-integrations stops every contribution. The two Toolsets it
			// publishes are NOT constructed here; see register_toolsets().
			new Elementor\Mcp\Server();
		}

		// Feature 069 — Rank Math ability suite (up to 61 abilities under
		// rank-math/*). Gated on Rank Math presence only.
		//
		// DELIBERATE DIVERGENCE from the Elementor block above: there is no
		// second entitlement-gated registration method. Rank Math's Content AI
		// and AI Visibility abilities are registered UNCONDITIONALLY and gate at
		// runtime inside execute(), returning rank_math_account_required or
		// content_ai_no_credits. This is a product decision, not an oversight —
		// unlike Elementor Pro, those features ship in Rank Math free core and
		// gate on cloud-account registration plus a credit balance rather than on
		// a separate plugin, so their availability can change without a plugin
		// activation and cannot be decided at registration time.
		// Do not "fix" this into the Elementor shape.
		// See specs/069-rank-math-abilities/research.md F7.
		if ( class_exists( '\RankMath\Helper' ) && $this->integration_on( 'rank-math' ) ) {
			$this->register_rank_math_abilities();
		}

		// Feature 120 — Site Kit by Google suite (14 abilities under site-kit/*). The Contact Form 7
		// shape rather than the Rank Math one: Site Kit has no entitlement that could change within a
		// request. Its Google CONNECTION can, but that is runtime state the guards already report per
		// call, and it is deliberately not a registration gate — the abilities must exist on an
		// unconnected site precisely so site-kit/get-status can say so.
		if ( Utilities\SiteKit\Site_Kit_Context::available() && $this->integration_on( 'site-kit' ) ) {
			$this->register_site_kit_abilities();
		}

		// Feature 103 — Contact Form 7 ability suite (25 abilities under contact-form-7/*).
		// Gated at boot, the Elementor shape rather than the Rank Math one: CF7 has no notion of
		// modules or entitlements that could change within a request, so there is nothing to defer
		// to runtime. The suite's guard still re-checks availability inside execute(), because CF7
		// can be deactivated after the abilities were registered in the same request.
		if ( class_exists( 'WPCF7_ContactForm' ) && $this->integration_on( 'contact-form-7' ) ) {
			$this->register_contact_form_7_abilities();
		}

		// Feature 104 — LiteSpeed Cache ability suite (60 abilities under litespeed/*).
		// Same boot-time gate as Contact Form 7: LiteSpeed has no notion of modules or entitlements
		// that could change within a request, so there is nothing to defer to runtime. The suite's
		// guard still re-checks availability inside execute(), because the plugin can be deactivated
		// after the abilities were registered in the same request.
		if ( class_exists( '\\LiteSpeed\\Core' ) && $this->integration_on( 'litespeed-cache' ) ) {
			$this->register_litespeed_abilities();
		}

		// Feature 105 — ACF suite. Gated on ACF being loaded at all; the 11 Pro-only abilities are
		// gated a second time, individually, because ACF Pro REPLACES free ACF rather than extending
		// it and only one edition is ever active. The per-ability gate is the FIELD TYPE, not
		// function_exists(): add_row()/update_row()/delete_row() ship in both editions, so a function
		// check would register six abilities on free ACF that can never succeed.
		if ( defined( 'ACF_VERSION' ) && function_exists( 'acf_get_setting' ) && $this->integration_on( 'acf' ) ) {
			$this->register_acf_abilities();
		}

		// Feature 106 — Yoast SEO suite. Gated only on Yoast being loaded, deliberately NOT on the
		// environment. Yoast unregisters its OWN abilities whenever is_production_mode() is false,
		// because indexables store permalinks and building them on staging bakes in the wrong ones.
		// That reasoning covers indexables and nothing else: settings, terms, sitemaps and tools are
		// just as valid on staging, and inheriting the gate would make all 62 invisible there.
		if ( defined( 'WPSEO_VERSION' ) && class_exists( '\\WPSEO_Options' ) && $this->integration_on( 'yoast-seo' ) ) {
			$this->register_yoast_abilities();
		}

		// Feature 132 — All in One SEO suite. The Contact Form 7 shape: AIOSEO has no modules or
		// entitlements that could change within a request, so the gate is at boot. Two symbols rather
		// than one, matching Aioseo_Guard and Integrations\All_In_One_Seo — the version constant is
		// defined before the aioseo() container is built, so it alone would report the plugin usable
		// while every read in the suite hit a null container. The guard re-checks inside execute(),
		// because AIOSEO can be deactivated after the abilities were registered in the same request.
		if ( defined( 'AIOSEO_VERSION' ) && function_exists( 'aioseo' ) && $this->integration_on( 'all-in-one-seo' ) ) {
			$this->register_aioseo_abilities();
		}

		// Feature 107 — Classic Editor ability suite (4 abilities under editor/*). Gated on the
		// plugin, so the group and its MCP tool exist only where they mean something.
		if ( defined( 'CLASSIC_EDITOR_VERSION' ) && class_exists( 'Classic_Editor' ) && $this->integration_on( 'classic-editor' ) ) {
			$this->register_classic_editor_abilities();
		}

		// Feature 109 — The Events Calendar ability suite (18 abilities under events/*). Gated on
		// the plugin AND its ORM, because every write in the suite goes through tribe_events().
		if ( class_exists( 'Tribe__Events__Main' ) && function_exists( 'tribe_events' ) && $this->integration_on( 'events-calendar' ) ) {
			$this->register_events_calendar_abilities();
		}

		// Feature 110 — Event Tickets ability suite (16 abilities under tickets/*). Gated on Event
		// Tickets alone: it does NOT require The Events Calendar, and tickets attach to pages too.
		if ( class_exists( 'Tribe__Tickets__Main' ) && class_exists( 'Tribe__Tickets__Tickets' ) && $this->integration_on( 'event-tickets' ) ) {
			$this->register_event_tickets_abilities();
		}
		// Feature 112 — WPCode ability suite (24 abilities under snippets/*). Gated on the snippet
		// class rather than a version constant: it is what the suite actually calls, so its absence
		// is what would break. WPCode's own five under wpcode/* are adopted by the toolset, not
		// re-registered here.
		if ( class_exists( 'WPCode_Snippet' ) && function_exists( 'wpcode' ) && $this->integration_on( 'wpcode' ) ) {
			$this->register_wpcode_abilities();
		}

		// Feature 116 — Loco Translate ability suite (14 abilities under translations/*). Gated on
		// the two classes the suite actually calls: the discovery root and the only sanctioned write
		// path. Loco registers no abilities of its own, so nothing here is adopted.
		if ( class_exists( 'Loco_package_Bundle' ) && class_exists( 'Loco_gettext_Compiler' ) && $this->integration_on( 'loco-translate' ) ) {
			$this->register_loco_translate_abilities();
		}

		// Feature 118 — cookie consent suite (22 abilities under consent/*). Gated through the guard
		// rather than on a class name here, so the two-symbol presence test lives in one place. The
		// consent plugin registers no abilities of its own, so nothing here is adopted.
		if ( Utilities\Consent\Consent_Guard::is_available() && $this->integration_on( 'cookieyes' ) ) {
			$this->register_consent_abilities();
		}

		// Feature 119 — email delivery suite (4 abilities under email/*). The mail plugin's own
		// abilities are adopted by prefix, not registered here.
		if ( Utilities\Email\Email_Guard::is_available() && $this->integration_on( 'wp-mail-smtp' ) ) {
			$this->register_email_abilities();
		}

		// Feature 121 — store suite. WooCommerce's own seven are adopted by prefix, not registered
		// here: it reserves the whole `woocommerce/` namespace and takes back any name we shadow.
		if ( Utilities\Store\Store_Guard::is_available() && $this->integration_on( 'woocommerce' ) ) {
			$this->register_store_abilities();
		}

		// Feature 127 — one suite per backup plugin. Registered unconditionally and deliberately:
		// each suite's guard answers at call time, so on a site without that plugin the abilities
		// are still discoverable and are the thing that says it is missing. Gating registration on
		// the plugin being installed would hide the answer exactly when "is this site backed up?"
		// is answered no.
		// Still gated on the switch, though: "no backup plugin installed" is an answer worth
		// having, but an operator who turned the integration off has said they do not want it.
		if ( $this->integration_on( 'updraftplus' ) ) {
			$this->register_updraftplus_abilities();
		}

		if ( $this->integration_on( 'all-in-one-wp-migration' ) ) {
			$this->register_all_in_one_abilities();
		}

		// Feature 130 — Redirection suite (8 abilities under redirects/*). The Contact Form 7 shape:
		// Redirection has no modules or entitlements that could change within a request, so the gate
		// is at boot. The suite's guard re-checks availability inside execute(), because the plugin
		// can be deactivated after the abilities were registered in the same request. Redirection
		// registers no abilities of its own, so nothing here is adopted.
		if ( class_exists( '\Red_Item' ) && $this->integration_on( 'redirection' ) ) {
			$this->register_redirection_abilities();
		}

		// Feature 100 — one Toolset dispatcher per ability group. Registered last
		// so every group it may cover already exists. Each declines to register
		// itself when its group has no registered abilities, which is why the two
		// conditional ones need no guard: with Elementor or Rank Math inactive,
		// their groups are empty and their Toolsets simply do not appear.
		$this->register_toolsets();
	}

	/**
	 * Feature 069 — instantiate the Rank Math ability classes.
	 *
	 * Each `new RankMath\<Class>()` line is added as the corresponding ability
	 * class lands across Batches 1-7 of
	 * specs/069-rank-math-abilities/tasks.md.
	 *
	 * @return void
	 */
	private function register_rank_math_abilities(): void {
		// Batch 1 — status / diagnostics.
		new RankMath\Get_Status();

		// Batch 2 — typed settings. One reader for all 20 panels, one writer per
		// Rank Math option blob, plus the two blobs that need their own ability.
		new RankMath\Get_Settings_Panel();
		new RankMath\Update_General_Settings();
		new RankMath\Update_Title_Settings();
		new RankMath\Update_Sitemap_Settings();
		new RankMath\Update_Instant_Indexing_Settings();
		new RankMath\Update_Robots_Txt();

		// Batch 3 — Instant Indexing.
		new RankMath\Submit_Urls();
		new RankMath\Get_Indexing_Log();
		new RankMath\Clear_Indexing_Log();
		new RankMath\Reset_Indexing_Key();

		// Batch 3 — module state.
		new RankMath\List_Modules();

		// Batch 3 — sitemap operations.
		new RankMath\Get_Sitemap_Health();
		new RankMath\List_Sitemap_Urls();
		new RankMath\Invalidate_Sitemap_Cache();

		// Batch 3 — virtual routes.
		new RankMath\Get_Llms_Status();
		new RankMath\Refresh_Llms_Route();

		// Batch 4 — redirections. Reads first, then writes; hard delete is
		// separate from the reversible status transitions so each can declare its
		// annotations honestly.
		new RankMath\List_Redirections();
		new RankMath\Find_Redirection();
		new RankMath\Get_Redirection_Stats();
		new RankMath\Export_Redirections();
		new RankMath\Create_Redirection();
		new RankMath\Update_Redirection();
		new RankMath\Change_Redirection_Status();
		new RankMath\Delete_Redirections();
		new RankMath\Delete_Trashed_Redirections();

		// Batch 4 — 404 monitor log.
		new RankMath\List_404_Logs();
		new RankMath\Delete_404_Logs();

		// Batch 4 — role capabilities. Read and reset only: no bulk writer, because
		// Helper::set_capabilities() strips capabilities from omitted roles. Grants
		// go through the plugin's existing users/add-role-capability.
		new RankMath\Get_Role_Capabilities();
		new RankMath\Reset_Role_Capabilities();

		// Batch 5 — status, maintenance tools, backups, import/export.
		new RankMath\Run_Maintenance_Tool();
		new RankMath\Export_Settings();
		new RankMath\Import_Settings();
		new RankMath\List_Backups();
		new RankMath\Create_Backup();
		new RankMath\Manage_Backup();
		new RankMath\Detect_Seo_Plugins();

		// Batch 6 — analytics.
		new RankMath\Get_Analytics_Summary();
		new RankMath\Get_Analytics_Rows();
		new RankMath\Get_Index_Status();
		new RankMath\Inspect_Url();

		// Batch 6 — post-level content and schema.
		new RankMath\Update_Seo_Meta();
		new RankMath\Bulk_Update_Meta();
		new RankMath\Update_Seo_Scores();
		new RankMath\Get_Primary_Term();
		new RankMath\Update_Primary_Term();
		new RankMath\Update_Post_Schemas();
		new RankMath\Delete_Post_Schemas();
		new RankMath\Get_Schema_Status();
		new RankMath\Get_Rendered_Head();
		new RankMath\Audit_Content_Seo();
		new RankMath\Get_Inbound_Links();
		new RankMath\Audit_Faq_Links();

		// Batch 7 — entitlement-gated. Registered UNCONDITIONALLY and gated at
		// runtime; see the block comment above register_rank_math_abilities().
		new RankMath\Get_Content_Ai_Status();
		new RankMath\Manage_Content_Ai_Prompts();
		new RankMath\Manage_Content_Ai_Output();
		new RankMath\Research_Keyword();
		new RankMath\Get_Ai_Visibility_Brand();
		new RankMath\Update_Ai_Visibility_Object();
	}

	/**
	 * Whether an integration's abilities should be registered at all.
	 *
	 * The Integrations switch, applied where it is cheapest and most complete: an integration that
	 * is off never registers, so there is nothing to refuse, nothing listed, and — because the
	 * Toolset dispatchers register last and only when their group has members — no dead
	 * `toolset/{group}` tool left fronting an empty group.
	 *
	 * Asks whether the integration was switched *off*, not whether it was switched *on*, and that
	 * is load-bearing. Default-on is stored as a seeded `true` written at `init` P1, while
	 * `wp_abilities_api_init` fires lazily on whatever first touches the registry — which can be
	 * earlier. Testing for an explicit `true` would therefore read "absent, so off" on a fresh site
	 * and silently register no integration abilities at all. A stored `false` is a decision; an
	 * unseeded key is not. See AcrossAI_Integration_Settings::is_switched_off().
	 *
	 * @since  0.0.42
	 * @param  string $group Integration group slug, as declared by its TAB_GROUP.
	 * @return bool
	 */
	private function integration_on( string $group ): bool {
		return ! AcrossAI_Integration_Settings::is_switched_off( $group );
	}

	/**
	 * Feature 067 — instantiate the free-Elementor ability classes.
	 *
	 * Each `new Elementor\<Class>()` line is added as the corresponding
	 * ability class lands in Phases 3-12 of tasks.md.
	 *
	 * @return void
	 */
	private function register_elementor_free_abilities(): void {
		// Group 3 — discovery / guidance.
		new Elementor\Get_Widget_Controls();
		// Group 1 — document / element operations.
		new Elementor\Get_Data();
		new Elementor\Get_Element();
		new Elementor\Find_Elements();
		new Elementor\Update_Element();
		new Elementor\Add_Container();
		new Elementor\Add_Widget();
		new Elementor\Merge_Element_Settings();
		new Elementor\Delete_Element();
		new Elementor\Remove_Element();
		new Elementor\Move_Element();
		new Elementor\Duplicate_Element();
		new Elementor\Reorder_Elements();
		// Document-scoped ops.
		new Elementor\Create_Page();
		new Elementor\Update_Page_Settings();
		new Elementor\Update_Data();
		new Elementor\Patch_Data();
		new Elementor\Clone_Data();
		// Widget shortcuts.
		new Elementor\Add_Heading();
		new Elementor\Add_Text_Editor();
		new Elementor\Add_Image();
		new Elementor\Add_Button();
		new Elementor\Add_Post_Tabs();
		// System & maintenance.
		new Elementor\Clear_Cache();
		new Elementor\Replace_Urls();
		new Elementor\Get_Maintenance_Mode();
		new Elementor\Update_Maintenance_Mode();
		// Theme Builder.
		new Elementor\Get_Theme_Builder_Conditions();
		new Elementor\Update_Theme_Builder_Conditions();
		// Discovery / guidance.
		new Elementor\Get_Official_Widget_Catalog();
		new Elementor\Get_Official_Pattern_Guidance();
		new Elementor\Get_Theme_Context();
		new Elementor\Get_Style_Guide();
		new Elementor\Evaluate_Render_Context();
		// Templates (Group 4 — full CRUD).
		new Elementor\List_Templates();
		new Elementor\Get_Template();
		new Elementor\Create_Template();
		new Elementor\Update_Template();
		new Elementor\Delete_Template();
		new Elementor\Restore_Template();
		new Elementor\Duplicate_Template();
		new Elementor\Empty_Trash();
		new Elementor\Export_Template();
		new Elementor\Import_Template();
		new Elementor\Find_Template_For_Pattern();
		// Kits & site settings.
		new Elementor\List_Kits();
		new Elementor\Get_Kit_Settings();
		new Elementor\Update_Kit_Settings();
		new Elementor\Set_Active_Kit();
		new Elementor\List_Global_Widgets();
		new Elementor\List_Experiments();
		new Elementor\Update_Experiment();
		// Design audits — aggregators, then the 27 individual audits they compose.
		//
		// Issue #243: these were registered as skeletons that returned a fabricated score
		// with no findings. They now carry real analyses over Design_Model, and the 11
		// mutating ones write through Design_Mutator behind a confirm gate.
		new Elementor\Evaluate_Design();
		new Elementor\Suggest_Design_Fixes();
		new Elementor\Score_Distinctiveness();
		new Elementor\Extract_Design_Tokens();

		// 14 read-only audit-* abilities.
		new Elementor\Audit_Column_Alignment_Rhythm();
		new Elementor\Audit_Column_Balance();
		new Elementor\Audit_Column_Dominance();
		new Elementor\Audit_Column_Necessity();
		new Elementor\Audit_Column_Patterns();
		new Elementor\Audit_Composition_Rhythm();
		new Elementor\Audit_Emphasis_Drift();
		new Elementor\Audit_Generic_Component_Repetition();
		new Elementor\Audit_Generic_Layout_Patterns();
		new Elementor\Audit_Layout_Mechanism_Fit();
		new Elementor\Audit_Native_Widget_Opportunities();
		new Elementor\Audit_Section_Rivalry();
		new Elementor\Audit_Separator_Discipline();
		new Elementor\Audit_Surface_Overuse();

		// 11 mutating design fixes. Every one is confirm-gated.
		new Elementor\Apply_Text_Hierarchy();
		new Elementor\Copy_Lane_Settings();
		new Elementor\Copy_Row_Balance();
		new Elementor\Enforce_Boundary_Coherence();
		new Elementor\Fix_Visible_Gap_Rhythm();
		new Elementor\Image_Widget_To_Background_Container();
		new Elementor\Normalize_Responsive_Values();
		new Elementor\Normalize_Section_Spacing_Rhythm();
		new Elementor\Reset_Negative_Margins_Subtree();
		new Elementor\Sync_Component_Variant();
		new Elementor\Zero_Container_Padding_Subtree();
	}

	/**
	 * Feature 067 — instantiate the Elementor Pro-gated ability classes.
	 *
	 * @return void
	 */
	private function register_elementor_pro_abilities(): void {
		// Custom Code CRUD (elementor_snippet CPT).
		new Elementor\List_Custom_Code();
		new Elementor\Get_Custom_Code();
		new Elementor\Create_Custom_Code();
		new Elementor\Update_Custom_Code();
		new Elementor\Delete_Custom_Code();
		// Form Submissions (Elementor Pro's e_submissions table).
		new Elementor\List_Form_Submissions();
		new Elementor\Get_Form_Submission();
		new Elementor\Delete_Form_Submission();
	}

	/**
	 * Feature 129/130 — instantiate the Query Monitor ability classes.
	 *
	 * The files live under Debugging/ beside the conflict-testing set but declare
	 * their own `query-monitor` tab_group, so they form their own toolset.
	 *
	 * @return void
	 */
	private function register_query_monitor_abilities(): void {
		new Debugging\Get_Environment();
		new Debugging\Get_Object_Cache_Status();
		new Debugging\Toggle_Query_Monitor();
		new Debugging\Profile_Url();
	}

	/**
	 * Feature 136 — instantiate the version-targeting ability classes.
	 *
	 * Reads only in this pass. The two abilities that actually move files are held back: WP
	 * Rollback performs a rollback as a sequence of steps driven one HTTP call at a time
	 * (MaintenanceMode, DownloadAsset, validate, BackupAsset, ReplaceAsset, Cleanup), so driving it
	 * from an ability means orchestrating someone else's internal state machine and guaranteeing
	 * maintenance mode is lifted if a later step fails. That wants its own design and review rather
	 * than riding along with the read abilities, which are useful on their own.
	 *
	 * @return void
	 */
	private function register_version_abilities(): void {
		new Versions\Get_Rollback_Status();
		new Versions\List_Asset_Versions();
		new Versions\Check_Asset_Rollback();
		// The write pair, deferred from the first pass of Feature 136 and now built on core's own
		// upgrader rather than WP Rollback's staged pipeline, so neither needs the host plugin.
		new Versions\Rollback_Plugin();
		new Versions\Rollback_Theme();
	}

	/**
	 * Feature 136 — instantiate the core version-pinning ability classes.
	 *
	 * Separate from the rollback reads because they answer to a different host plugin: WP Downgrade
	 * pins core, WP Rollback re-versions plugins and themes, and neither can do the other's job.
	 *
	 * @return void
	 */
	private function register_core_pin_abilities(): void {
		new Versions\Get_Core_Pin();
		new Versions\Set_Core_Pin();
		new Versions\Clear_Core_Pin();
	}

	/**
	 * Feature 130 — instantiate the Redirection ability classes.
	 *
	 * @return void
	 */
	private function register_redirection_abilities(): void {
		// Reads first, then writes; the destructive delete is separate from the
		// reversible update so each declares its annotations honestly.
		new Redirection\List_Redirects();
		new Redirection\List_Groups();
		new Redirection\List_404_Errors();
		new Redirection\List_Hit_Log();
		new Redirection\Get_Settings();
		new Redirection\Create_Redirect();
		new Redirection\Update_Redirect();
		new Redirection\Delete_Redirects();
	}

	/**
	 * Instantiate the Toolset dispatchers (Feature 100).
	 *
	 * One per ability group. Each is four declarations over
	 * {@see Toolset\Base_Toolset_Ability}, which holds every shared behaviour.
	 * Adding a group means adding one file here and one `new` line — if it ever
	 * takes more, the shared class is missing something.
	 *
	 * No conditional guards: a Toolset whose group has no registered abilities
	 * declines to register itself, so Elementor and Rank Math drop out on their
	 * own when their host plugin is absent.
	 *
	 * @since  0.0.34
	 * @return void
	 */
	private function register_toolsets(): void {
		new Toolset\Content();
		new Toolset\Blocks();
		new Toolset\Appearance();
		new Toolset\Configuration();
		new Toolset\Users();
		new Toolset\Updates();
		new Toolset\Cron();
		new Toolset\Cache();
		// Rebuilt per plugin, as every other integration Toolset is: one named
		// for UpdraftPlus and one for All-in-One WP Migration, each reaching its
		// own plugin directly. The provider layer and the `backups/*` abilities
		// that sat behind the retired `toolset/backups` are gone with it.
		new Toolset\UpdraftPlus();
		new Toolset\All_In_One();
		new Toolset\Database();
		new Toolset\Files();
		new Toolset\Diagnostics();
		new Toolset\Elementor();
		new Toolset\Rank_Math();
		new Toolset\Site_Kit();

		// Spans every Toolset above that is NOT a server-type default, so a
		// client whose cached tools/list predates a plugin can still reach it.
		// Registered last only for readability — it collects its groups through
		// a filter, so order does not matter.
		new Toolset\Integrations();

		// Not a dispatcher — it explains how the dispatchers are called, and is
		// registered here because it is advertised to the transport the same way.
		new Toolset\Guide();

		/*
		 * Feature 122 — the pair published onto MCP servers this plugin does not own.
		 *
		 * Constructed UNCONDITIONALLY, which is the whole point of them being generic. They were
		 * built inside the Elementor block, and on a site where Elementor is deactivated that meant
		 * neither registered: not merely unadvertised, but absent from the protected-slug list too.
		 * A second host would then have had to construct them itself and the two hosts would race
		 * to be first.
		 *
		 * Registration here is cheap and inert on a site with no host server. Both declare
		 * `is_host_only()`, so they are withheld from the `acrossai` server type, the transport's
		 * Tools picker, `toolset/integrations` and `toolset/server-guide` — while staying protected,
		 * so they never appear at page=acrossai-abilities-manager either. Nothing can reach them
		 * until a `Host_Mcp_Server` subclass contributes them to a host that is actually present,
		 * and that subclass carries its own gate.
		 *
		 * So: registration is unconditional, advertising is gated. Adding a second host touches
		 * only its own subclass.
		 */
		new Mcp\Site_Toolset();
		new Mcp\Site_Guide();

		$this->register_integration_toolsets( array(
			'content',
			'blocks',
			'appearance',
			'configuration',
			'users',
			'updates',
			'cron',
			'cache',
			'updraftplus',
			'all-in-one-wp-migration',
			'database',
			'files',
			'diagnostics',
			'elementor',
			'rank-math',
			'site-kit',
			'integrations',
		) );
	}

	/**
	 * Feature 120 — instantiate the Site Kit ability classes.
	 *
	 * @return void
	 */
	private function register_site_kit_abilities(): void {
		// Connection and setup. get-status is first because every other ability in the
		// suite fails back to it.
		new SiteKit\Get_Status();
		new SiteKit\Get_Key_Metrics();
		new SiteKit\Update_Key_Metrics();

		// Modules, their settings and their sharing.
		new SiteKit\List_Modules();
		new SiteKit\Get_Module_Settings();
		new SiteKit\Update_Module_Settings();
		new SiteKit\Set_Module_State();
		new SiteKit\Get_Sharing_Settings();
		new SiteKit\List_Module_Datapoints();

		// Google data, read through Site Kit's own module clients.
		new SiteKit\Get_Search_Analytics();
		new SiteKit\Get_Analytics_Report();
		new SiteKit\Get_Pagespeed_Insights();
		new SiteKit\Get_Adsense_Report();
		new SiteKit\Get_Module_Data();
	}

	/**
	 * Feature 103 — instantiate the Contact Form 7 ability classes.
	 *
	 * @return void
	 */
	private function register_contact_form_7_abilities(): void {
		// Forms — the lifecycle.
		new ContactForm7\List_Forms();
		new ContactForm7\Get_Form();
		new ContactForm7\Find_Form();
		new ContactForm7\Get_Form_Shortcode();
		new ContactForm7\Create_Form();
		new ContactForm7\Update_Form();
		new ContactForm7\Duplicate_Form();
		new ContactForm7\Delete_Form();

		// Fields — the template and its tags.
		new ContactForm7\List_Form_Fields();
		new ContactForm7\List_Field_Types();
		new ContactForm7\Get_Form_Template();
		new ContactForm7\Update_Form_Template();
		new ContactForm7\Add_Form_Field();
		new ContactForm7\Update_Form_Field();
		new ContactForm7\Remove_Form_Field();

		// Mail — notifications and their tags.
		new ContactForm7\Get_Mail();
		new ContactForm7\Update_Mail();
		new ContactForm7\Toggle_Mail_2();
		new ContactForm7\List_Mail_Tags();
		new ContactForm7\Validate_Mail_Tags();

		// Messages.
		new ContactForm7\Get_Messages();
		new ContactForm7\Update_Messages();

		// Settings and validation.
		new ContactForm7\Get_Additional_Settings();
		new ContactForm7\Update_Additional_Settings();
		new ContactForm7\Validate_Form_Config();
	}

	/**
	 * Feature 104 — instantiate the LiteSpeed Cache ability classes.
	 *
	 * @return void
	 */
	private function register_litespeed_abilities(): void {
		// Purging and cache control (9).
		new LiteSpeed\Get_Cache_Status();
		new LiteSpeed\Get_Purge_Settings();
		new LiteSpeed\Purge_By_Tag();
		new LiteSpeed\Purge_Cache();
		new LiteSpeed\Purge_Post();
		new LiteSpeed\Purge_Taxonomy();
		new LiteSpeed\Purge_Url();
		new LiteSpeed\Update_Purge_Settings();
		new LiteSpeed\Update_Scheduled_Purge();

		// Cache settings (10).
		new LiteSpeed\Get_Cache_Settings();
		new LiteSpeed\Get_Cache_Vary();
		new LiteSpeed\List_Cache_Exclusions();
		new LiteSpeed\Set_Cache_State();
		new LiteSpeed\Update_Cache_Exclusions();
		new LiteSpeed\Update_Cache_Scope();
		new LiteSpeed\Update_Cache_Settings();
		new LiteSpeed\Update_Cache_Ttl();
		new LiteSpeed\Update_Cache_Vary();
		new LiteSpeed\Update_Guest_Mode();

		// Page optimisation (11).
		new LiteSpeed\Get_Optimization_Settings();
		new LiteSpeed\Get_Optimization_Status();
		new LiteSpeed\List_Optimization_Exclusions();
		new LiteSpeed\Purge_Optimization_Cache();
		new LiteSpeed\Update_Css_Settings();
		new LiteSpeed\Update_Font_Settings();
		new LiteSpeed\Update_Html_Settings();
		new LiteSpeed\Update_Js_Settings();
		new LiteSpeed\Update_Localization_Settings();
		new LiteSpeed\Update_Optimization_Exclusions();
		new LiteSpeed\Update_Tuning_Settings();

		// Media and lazy load (6).
		new LiteSpeed\Get_Media_Settings();
		new LiteSpeed\Get_Media_Status();
		new LiteSpeed\Update_Lazyload_Settings();
		new LiteSpeed\Update_Media_Exclusions();
		new LiteSpeed\Update_Placeholder_Settings();
		new LiteSpeed\Update_Viewport_Settings();

		// Crawler (8).
		new LiteSpeed\Get_Crawler_Map();
		new LiteSpeed\Get_Crawler_Settings();
		new LiteSpeed\Get_Crawler_Status();
		new LiteSpeed\List_Crawlers();
		new LiteSpeed\Reset_Crawler();
		new LiteSpeed\Run_Crawler();
		new LiteSpeed\Set_Crawler_State();
		new LiteSpeed\Update_Crawler_Settings();

		// Database reporting (3).
		new LiteSpeed\Get_Autoload_Summary();
		new LiteSpeed\Get_Database_Summary();
		new LiteSpeed\Plan_Database_Cleanup();
		new LiteSpeed\List_Myisam_Tables();

		// Object and browser cache (5).
		new LiteSpeed\Flush_Object_Cache();
		new LiteSpeed\Get_Object_Cache_Status();
		new LiteSpeed\Test_Object_Cache_Connection();
		new LiteSpeed\Update_Browser_Cache_Settings();
		new LiteSpeed\Update_Object_Cache_Settings();

		// Presets and diagnostics (8).
		new LiteSpeed\Apply_Preset();
		new LiteSpeed\Export_Settings();
		new LiteSpeed\Get_Advanced_Settings();
		new LiteSpeed\Get_Environment_Report();
		new LiteSpeed\List_Preset_Backups();
		new LiteSpeed\List_Settings_Areas();
		new LiteSpeed\Restore_Preset_Backup();
		new LiteSpeed\Update_Advanced_Settings();
	}

	/**
	 * Feature 105 — instantiate the ACF ability classes.
	 *
	 * All 16 are constructed here; the Pro-only ones decline at execute() time via
	 * Base_Acf_Ability::requires_pro() and required_field_types() rather than being skipped here.
	 * Registering them and having them report acf_pro_required is better than omitting them
	 * silently: an operator on free ACF sees the ability exists and learns why it will not run,
	 * which a missing row cannot tell them.
	 *
	 * @return void
	 */
	private function register_acf_abilities(): void {
		// Field values on any target — free ACF and Pro (5).
		new Acf\Delete_Acf_Field();
		new Acf\Get_Acf_Field();
		new Acf\Get_Acf_Fields();
		new Acf\Update_Acf_Field();
		new Acf\Update_Acf_Fields();

		// Repeater and flexible-content rows — Pro only (6).
		new Acf\Add_Acf_Flex_Layout();
		new Acf\Add_Acf_Repeater_Row();
		new Acf\Remove_Acf_Flex_Layout();
		new Acf\Remove_Acf_Repeater_Row();
		new Acf\Reorder_Acf_Repeater_Rows();
		new Acf\Update_Acf_Repeater_Row();

		// ACF blocks — Pro only (5).
		new Acf\Get_Acf_Block_Fields();
		new Acf\Insert_Acf_Block();
		new Acf\List_Acf_Blocks();
		new Acf\Register_Acf_Block();
		new Acf\Update_Acf_Block_Data();
	}

	/**
	 * Feature 106 — instantiate the Yoast SEO ability classes.
	 *
	 * @return void
	 */
	private function register_yoast_abilities(): void {
		// yoast-settings (16).
		new Yoast\Export_Settings();
		new Yoast\Get_Seo_Settings();
		new Yoast\Import_Settings();
		new Yoast\List_Settings_Areas();
		new Yoast\Update_Archive_Settings();
		new Yoast\Update_Breadcrumb_Settings();
		new Yoast\Update_Crawl_Settings();
		new Yoast\Update_General_Settings();
		new Yoast\Update_Knowledge_Graph();
		new Yoast\Update_Llms_Settings();
		new Yoast\Update_Rss_Settings();
		new Yoast\Update_Advanced_Settings();
		new Yoast\Update_Integration_Settings();
		new Yoast\Update_Schema_Settings();
		new Yoast\Update_Social_Defaults();
		new Yoast\Update_Social_Profiles();
		new Yoast\Update_Title_Templates();
		new Yoast\Update_Webmaster_Verification();

		// yoast-content-types (6).
		new Yoast\Get_Post_Type_Seo();
		new Yoast\Get_Taxonomy_Seo();
		new Yoast\List_Content_Type_Settings();
		new Yoast\Update_Archive_Seo();
		new Yoast\Update_Post_Type_Seo();
		new Yoast\Update_Taxonomy_Seo();

		// yoast-terms (6).
		new Yoast\Clear_Term_Seo();
		new Yoast\Get_Primary_Term();
		new Yoast\Get_Term_Seo();
		new Yoast\List_Term_Seo();
		new Yoast\Set_Primary_Term();
		new Yoast\Update_Term_Seo();

		// yoast-indexables (9).
		new Yoast\Get_Author_Archive_Seo();
		new Yoast\Get_Homepage_Seo();
		new Yoast\Get_Indexable();
		new Yoast\Get_Post_Type_Archive_Seo();
		new Yoast\Get_System_Page_Seo();
		new Yoast\List_Indexables();
		new Yoast\Update_Homepage_Seo();
		new Yoast\Update_Post_Type_Archive_Seo();
		new Yoast\Update_System_Page_Seo();

		// yoast-sitemap (6).
		new Yoast\Get_Sitemap_Settings();
		new Yoast\Get_Sitemap_Status();
		new Yoast\Invalidate_Sitemap();
		new Yoast\Invalidate_Sitemap_For_Post();
		new Yoast\List_Sitemap_Index();
		new Yoast\Update_Sitemap_Settings();

		// yoast-indexing (5).
		new Yoast\Cleanup_Indexables();
		new Yoast\Get_Indexation_Counts();
		new Yoast\Get_Indexing_Status();
		new Yoast\Reset_Indexing();
		new Yoast\Run_Indexing();

		// yoast-content (8).
		new Yoast\Get_Content_Seo_Issues();
		new Yoast\Get_Internal_Links();
		new Yoast\Get_Keyphrase_Usage();
		new Yoast\Get_Seo_Score_Summary();
		new Yoast\List_Cornerstone_Content();
		new Yoast\List_Low_Score_Content();
		new Yoast\List_Orphaned_Content();
		new Yoast\Set_Cornerstone();

		// yoast-tools (6).
		new Yoast\Get_First_Time_Config();
		new Yoast\Get_Import_Status();
		new Yoast\Get_Robots_Settings();
		new Yoast\Get_Seo_Status();
		new Yoast\List_Conflicting_Plugins();
		new Yoast\Update_Robots_Settings();
	}

	/**
	 * Feature 132 — instantiate the All in One SEO ability classes.
	 *
	 * Twelve abilities, and the set is defined by subtraction: AIOSEO ships thirteen of its own, so
	 * nothing here reads or writes one post's SEO, lists posts by missing field or TruSEO score,
	 * touches robots.txt rules, lists notifications, or reads the raw settings tree. What is left is
	 * the typed settings layer AIOSEO documents as having no writer, the per-object search appearance
	 * in its second option store, sitemap health and regeneration, settings backups, and the batch
	 * post write.
	 *
	 * @since  0.0.44
	 * @return void
	 */
	private function register_aioseo_abilities(): void {
		// Settings — the documented gap.
		new Aioseo\Get_Settings_Panel();
		new Aioseo\Update_Settings_Panel();
		new Aioseo\Get_Object_Settings();
		new Aioseo\Update_Object_Settings();
		// Sitemaps — AIOSEO ships none.
		new Aioseo\Get_Sitemap_Health();
		new Aioseo\Regenerate_Sitemap();
		// Status and backups.
		new Aioseo\Get_Status();
		new Aioseo\List_Backups();
		new Aioseo\Create_Backup();
		new Aioseo\Manage_Backup();
		new Aioseo\Dismiss_Notification();
		// Content.
		new Aioseo\Bulk_Update_Post_Seo();
	}

	/**
	 * One dispatcher per integration that does not already have a hand-written one.
	 *
	 * The thirteen above are curated first-party groups: few, stable, and worth a file each.
	 * Integrations are the opposite — the set grows, and each has already declared its group, label and
	 * description once. Generating from that declaration is what keeps adding an integration to a
	 * single class, instead of a class here plus a tagger entry plus a `new` line.
	 *
	 * Forgetting a dispatcher is otherwise silent: the group still gets a tab, a count and a REST
	 * filter, and is simply unreachable over MCP, with `Base_Toolset_Ability` then telling callers to
	 * "use that tool instead" for a tool that was never created.
	 *
	 * `Elementor` and `Rank_Math` are integrations that predate this and keep their own classes; their
	 * groups appear in `$claimed` so nothing double-registers. Retiring either is now a deletion — the
	 * descriptor already carries the same four answers.
	 *
	 * @since  0.0.34
	 * @param  string[] $claimed Groups a hand-written dispatcher already serves.
	 * @return void
	 */
	private function register_integration_toolsets( array $claimed ): void {
		foreach ( Integrations\AcrossAI_Toolset_Integrations::all() as $group => $integration ) {
			if ( in_array( $group, $claimed, true ) ) {
				continue;
			}

			new Toolset\Integration_Toolset( $integration );
		}
	}


	/**
	 * Feature 107 — instantiate the Classic Editor ability classes.
	 *
	 * @since  0.0.34
	 * @return void
	 */
	private function register_classic_editor_abilities(): void {
		new ClassicEditor\Get_Editor_Settings();
		new ClassicEditor\Update_Editor_Settings();
		new ClassicEditor\Get_Post_Editor();
		new ClassicEditor\Get_Post_Type_Editor_Support();
	}

	/**
	 * Feature 109 — instantiate The Events Calendar ability classes.
	 *
	 * @since  0.0.34
	 * @return void
	 */
	private function register_events_calendar_abilities(): void {
		new EventsCalendar\Create_Event();
		new EventsCalendar\Create_Organizer();
		new EventsCalendar\Create_Venue();
		new EventsCalendar\Get_Calendar_Settings();
		new EventsCalendar\Get_Event();
		new EventsCalendar\Get_Organizer();
		new EventsCalendar\Get_Venue();
		new EventsCalendar\List_Event_Categories();
		new EventsCalendar\List_Events();
		new EventsCalendar\List_Organizers();
		new EventsCalendar\List_Venues();
		new EventsCalendar\Set_Event_Categories();
		new EventsCalendar\Trash_Event();
		new EventsCalendar\Trash_Organizer();
		new EventsCalendar\Trash_Venue();
		new EventsCalendar\Update_Event();
		new EventsCalendar\Update_Organizer();
		new EventsCalendar\Update_Venue();
	}

	/**
	 * Feature 110 — instantiate the Event Tickets ability classes.
	 *
	 * @since  0.0.34
	 * @return void
	 */
	private function register_event_tickets_abilities(): void {
		new EventTickets\Check_In_Attendee();
		new EventTickets\Create_Ticket();
		new EventTickets\Delete_Ticket();
		new EventTickets\Get_Attendee_Summary();
		new EventTickets\Get_Capacity_Report();
		new EventTickets\Get_Order();
		new EventTickets\Get_Sales_Summary();
		new EventTickets\Get_Ticket();
		new EventTickets\Get_Ticket_Settings();
		new EventTickets\List_Attendees();
		new EventTickets\List_Orders();
		new EventTickets\List_Ticket_Providers();
		new EventTickets\List_Tickets();
		new EventTickets\Set_Ticket_Capacity();
		new EventTickets\Undo_Check_In();
		new EventTickets\Update_Ticket();
	}

	/**
	 * Feature 112 — instantiate the WPCode ability classes.
	 *
	 * Category_Registrar is deliberately absent: it is a singleton with a private constructor,
	 * hooked via instance() above. Constructing it here would be a fatal on every page load.
	 *
	 * @since  0.0.34
	 * @return void
	 */
	private function register_wpcode_abilities(): void {
		new WPCode\List_Snippets();
		new WPCode\Get_Snippet();
		new WPCode\Create_Snippet();
		new WPCode\Update_Snippet();
		new WPCode\Delete_Snippet();
		new WPCode\Activate_Snippet();
		new WPCode\Deactivate_Snippet();
		new WPCode\Duplicate_Snippet();
		new WPCode\Set_Location();
		new WPCode\Set_Conditional_Logic();
		new WPCode\List_Locations();
		new WPCode\Get_Global_Scripts();
		new WPCode\Update_Global_Scripts();
		new WPCode\Get_Snippet_Status();
		new WPCode\List_Snippet_Errors();
		new WPCode\Clear_Snippet_Errors();
		new WPCode\Search_Library();
		new WPCode\Get_Library_Snippet();
		new WPCode\Install_Library_Snippet();
		new WPCode\List_Packs();
		new WPCode\Apply_Pack();
		new WPCode\List_Snippet_Updates();
		new WPCode\Update_Snippet_From_Library();
		new WPCode\Install_Shared_Snippet();
	}

	/**
	 * Feature 116 — instantiate the Loco Translate ability classes.
	 *
	 * Category_Registrar is deliberately absent: it is a singleton with a private constructor, hooked
	 * via instance() above. Constructing it here would be a fatal on every page load.
	 *
	 * @since  0.0.34
	 * @return void
	 */
	private function register_loco_translate_abilities(): void {
		new LocoTranslate\List_Bundles();
		new LocoTranslate\Get_Bundle();
		new LocoTranslate\Get_Translation_Status();
		new LocoTranslate\List_Locales();
		new LocoTranslate\List_Strings();
		new LocoTranslate\Get_String();
		new LocoTranslate\Update_Strings();
		new LocoTranslate\Create_Translation_File();
		new LocoTranslate\Delete_Translation_File();
		new LocoTranslate\Compile_Translations();
		new LocoTranslate\Sync_Translations();
		new LocoTranslate\Extract_Strings();
		new LocoTranslate\List_Available_Languages();
		new LocoTranslate\Fetch_Translations();
	}

	/**
	 * Feature 118 — instantiate the cookie consent ability classes.
	 *
	 * Category_Registrar is deliberately absent: it is a singleton with a private constructor, hooked
	 * via instance() above. Constructing it here would be a fatal on every page load.
	 *
	 * @since  0.0.34
	 * @return void
	 */
	private function register_consent_abilities(): void {
		new Consent\List_Cookies();
		new Consent\Get_Cookie();
		new Consent\Add_Cookie();
		new Consent\Update_Cookie();
		new Consent\Delete_Cookie();
		new Consent\List_Categories();
		new Consent\Get_Category();
		new Consent\Update_Category();
		new Consent\List_Banners();
		new Consent\Get_Banner();
		new Consent\Get_Banner_Status();
		new Consent\Rebuild_Banner();
		new Consent\Get_Consent_Settings();
		new Consent\Update_Consent_Settings();
		new Consent\Get_Google_Consent_Mode();
		new Consent\Update_Google_Consent_Mode();
		new Consent\List_Languages();
		new Consent\Set_Languages();
		new Consent\Get_Consent_Log_Statistics();
		new Consent\Get_Pageview_Statistics();
		new Consent\Get_Scan_Status();
		new Consent\Get_Google_Consent_Mode_Status();
	}

	/**
	 * Feature 119 — instantiate the email delivery ability classes.
	 *
	 * Category_Registrar is deliberately absent: it is a singleton with a private constructor, hooked
	 * via instance() above. Constructing it here would be a fatal on every page load.
	 *
	 * @since  0.0.34
	 * @return void
	 */
	private function register_email_abilities(): void {
		new Email\Get_Delivery_Settings();
		new Email\Get_Delivery_Status();
		new Email\Send_Test_Email();
		new Email\Update_Delivery_Settings();
	}

	/**
	 * Feature 121 — instantiate the store ability classes.
	 *
	 * Category_Registrar is deliberately absent: it is a singleton with a private constructor, hooked
	 * via instance() above. Constructing it here would be a fatal on every page load.
	 *
	 * @since  0.0.34
	 * @return void
	 */
	/**
	 * Feature 127 — the UpdraftPlus suite.
	 *
	 * @since  0.0.35
	 * @return void
	 */
	private function register_updraftplus_abilities(): void {
		new UpdraftPlus\Get_Status();
		new UpdraftPlus\List_Backups();
		new UpdraftPlus\Get_Backup();
		new UpdraftPlus\Check_Exposure();
		new UpdraftPlus\Get_Backup_Progress();
		new UpdraftPlus\Start_Backup();
		new UpdraftPlus\Delete_Backup();
		new UpdraftPlus\Restore_Backup();
	}

	/**
	 * Feature 127 — the All-in-One WP Migration suite.
	 *
	 * @since  0.0.35
	 * @return void
	 */
	private function register_all_in_one_abilities(): void {
		new AllInOne\Get_Status();
		new AllInOne\List_Backups();
		new AllInOne\Get_Backup();
		new AllInOne\Check_Exposure();
		new AllInOne\Get_Export_Progress();
		new AllInOne\Start_Export();
		new AllInOne\Set_Backup_Label();
		new AllInOne\Delete_Backup();
		new AllInOne\Restore_Backup();
	}

	private function register_store_abilities(): void {
		new Store\Get_Store_Status();
		new Store\Get_Product();
		new Store\Update_Product_Details();
		new Store\Set_Product_Taxonomy();
		new Store\Set_Product_Images();
		new Store\Set_Product_Attributes();
		new Store\Create_Variable_Product();
		new Store\Generate_Variations();
		new Store\Update_Variation();
		new Store\Schedule_Sale();
		new Store\Bulk_Update_Prices();
		new Store\Get_Stock();
		new Store\Adjust_Stock();
		new Store\List_Low_Stock();
		new Store\Get_Order();
		new Store\List_Order_Notes();
		new Store\Refund_Order();
		new Store\List_Customers();
		new Store\Get_Customer();
		new Store\Create_Customer();
		new Store\Update_Customer();
		new Store\Export_Customers();
		new Store\Get_Sales_Summary();
		new Store\List_Top_Products();
		new Store\List_Coupons();
		new Store\Get_Coupon();
		new Store\Create_Coupon();
		new Store\Update_Coupon();
		new Store\Get_Tax_Rates();
		new Store\Add_Tax_Rate();
		new Store\List_Shipping_Zones();
		new Store\List_Shipping_Classes();
		new Store\Get_Store_Settings();
		new Store\Update_Store_Settings();
	}
}
