<?php

/**
 * Autoload.
 */
require __DIR__ . '/vendor/autoload.php';

use SolveBeam\GitHubActionsToolkit\Core;
use SolveBeam\GitHubActionsToolkit\Shell;
use SolveBeam\GitHubActionsToolkit\Style;
use SolveBeam\GitHubActionsToolkit\Summary;

/**
 * Default environment variables.
 * 
 * @link https://docs.github.com/en/actions/writing-workflows/choosing-what-your-workflow-does/variables#default-environment-variables
 */
$repository = getenv( 'GITHUB_REPOSITORY' );

/**
 * Setup.
 */
$core  = new Core();
$style = new Style();
$shell = new Shell();

$username = $core->get_required_input( 'username' );
$password = $core->get_required_input( 'password' );

$slug = $core->get_required_input( 'slug' );

$tag = $core->get_required_input( 'tag' );

$version = ltrim( $tag, 'v' );

$dry_run = $core->get_boolean_input( 'dry-run' );

$svn_url = "https://plugins.svn.wordpress.org/$slug";

$svn_url_tag = "$svn_url/tags/$version";

$public_url  = "https://wordpress.org/plugins/$slug/";
$trac_url    = "https://plugins.trac.wordpress.org/browser/$slug/";
$release_url = "https://github.com/$repository/releases/tag/$tag";

/**
 * Outputs.
 */
$core->set_output( 'public-url', $public_url );
$core->set_output( 'svn-url', $svn_url );
$core->set_output( 'svn-tag-url', $svn_url_tag );

/**
 * Filename.
 * 
 * Notation: “{name}.{version}”.
 * 
 * @link https://developer.wordpress.org/cli/commands/dist-archive/
 */
$filename = "$slug.$version.zip";

/**
 * Files.
 */
$work_dir = tempnam( sys_get_temp_dir(), '' );

unlink( $work_dir );

mkdir( $work_dir );

$archives_dir = $work_dir . '/archives';
$plugins_dir  = $work_dir . '/plugins';
$svn_dir      = $work_dir . '/svn';

mkdir( $archives_dir );
mkdir( $plugins_dir );
mkdir( $svn_dir );

$plugin_dir = $plugins_dir . '/' . $slug;

$readme_file = $plugin_dir . '/readme.txt';

/**
 * Start.
 */
$core->start_group( 'ℹ️ Release to WordPress.org' );

echo '• ', $style->bold( 'Subversion URL:' ), ' ', $svn_url, PHP_EOL;
echo '• ', $style->bold( 'Subversion username:' ), ' ', $username, PHP_EOL;
echo '• ', $style->bold( 'Dry run:' ), ' ', $dry_run ? 'yes' : 'no', PHP_EOL;

$core->end_group();

$changes = [];

$commands = [];

$error = null;

try {
	/**
	 * GitHub release.
	 */
	$core->start_group( '🐙 Check GitHub release' );

	$command = sprintf(
		'gh release view %s --repo %s',
		escapeshellarg( $tag ),
		escapeshellarg( $repository )
	);

	$commands[] = $command;

	$shell->must_run( $command );

	$core->end_group();

	/**
	 * Download release.
	 *
	 * @link https://cli.github.com/manual/gh_release_download
	 */
	$core->start_group( '📥 Download plugin' );

	$command = sprintf(
		'gh release download %s --pattern %s --dir %s --repo %s',
		escapeshellarg( $tag ),
		escapeshellarg( $filename ),
		escapeshellarg( $archives_dir ),
		escapeshellarg( $repository )
	);

	$commands[] = $command;

	$shell->must_run( $command );

	$core->end_group();

	/**
	 * Unzip.
	 */
	$core->start_group( '📦 Unzip plugin' );

	$command = sprintf(
		'unzip %s -d %s',
		escapeshellarg( $archives_dir . '/' . $filename ),
		escapeshellarg( $plugins_dir )
	);

	$commands[] = $command;

	$shell->must_run( $command );

	$core->end_group();

	/**
	 * Parse stable tag.
	 */
	if ( ! is_file( $readme_file ) ) {
		throw new RuntimeException( "The archive $filename does not contain the file $slug/readme.txt." );
	}

	$readme_content = file_get_contents( $readme_file );

	$pattern = "/Stable tag: (.*)/";

	$stable_tag = '';

	if ( 1 === preg_match( $pattern, $readme_content, $matches ) ) {
		$stable_tag = trim( $matches[1] );
	}

	if ( $stable_tag !== $version ) {
		throw new RuntimeException( "Stable tag in readme.txt ($stable_tag) is not equal to release version ($version)." );
	}

	/**
	 * Check tag existence.
	 */
	$core->start_group( '🔎 Check tag existence' );

	$command = sprintf(
		'svn info %s',
		escapeshellarg( $svn_url_tag )
	);

	$commands[] = $command;

	$result_code = $shell->run( $command );

	if ( 0 === $result_code ) {
		throw new RuntimeException( "There is already a tag for version $version: $svn_url_tag" );
	}

	$core->end_group();

	/**
	 * Subversion.
	 * 
	 * @link https://stackoverflow.com/a/122291
	 */
	$core->start_group( '⬇ Subversion checkout WordPress.org' );

	$command = sprintf(
		'svn checkout %s %s --depth immediates',
		escapeshellarg( $svn_url ),
		escapeshellarg( $svn_dir )
	);

	$commands[] = $command;

	$shell->must_run( $command );

	chdir( $svn_dir );

	$command = 'svn update trunk --set-depth infinity';

	$commands[] = $command;

	$shell->must_run( $command );

	$core->end_group();

	/**
	 * Synchronize.
	 * 
	 * @link http://stackoverflow.com/a/14789400
	 * @link http://askubuntu.com/a/476048
	 */
	$core->start_group( '🔄 Synchronize plugin' );

	$command = sprintf(
		'rsync --archive --delete --verbose %s %s',
		escapeshellarg( $plugin_dir . '/' ),
		escapeshellarg( $svn_dir . '/trunk/' )
	);

	$commands[] = $command;

	$shell->must_run( $command );

	$core->end_group();

	/**
	 * Subversion modifications.
	 */
	$core->start_group( '💾 Subversion modifications' );

	$command = 'svn status';

	$commands[] = $command;

	$shell->must_run( $command );

	$output = shell_exec( 'svn status --xml' );

	$xml = simplexml_load_string( $output );

	if ( false === $xml ) {
		throw new RuntimeException( 'A problem occurred while reading the `svn status --xml`.' );
	}

	foreach ( $xml->target->entry as $entry ) {
		$path = (string) $entry['path'];

		$wc_status = (string) $entry->{'wc-status'}['item'];

		/**
		 * Peg revision.
		 *
		 * A path can point to different files over time, because files can be
		 * renamed, deleted or replaced. Subversion therefore allows a revision
		 * to be appended to a path, as in `path@REV`, to identify which file is
		 * meant: the one that had this path in revision `REV`. This is called
		 * a peg revision.
		 *
		 * Subversion applies this notation to every path argument and treats
		 * everything after the last at sign as the peg revision. A file like
		 * `icon@2x.png` is therefore read as the path `icon` with peg revision
		 * `2x.png`, and `svn add` and `svn rm` fail with “a peg revision is not
		 * allowed here”.
		 *
		 * Because only the last at sign counts, we append one to every path:
		 * `icon@2x.png@` is read as the path `icon@2x.png` with an empty peg
		 * revision. This is harmless for paths without an at sign.
		 *
		 * @link https://svnbook.red-bean.com/en/1.7/svn.advanced.pegrevs.html
		 */
		switch ( $wc_status ) {
			case 'missing':
				$command = sprintf(
					'svn rm %s',
					escapeshellarg( $path . '@' )
				);

				$commands[] = $command;

				$shell->must_run( $command );

				$changes[] = [ '➖ Removed', $path ];

				break;
			case 'modified':
				// Modified entry will be commited.

				$changes[] = [ '✏️ Modified', $path ];

				break;
			case 'unversioned':
				$command = sprintf(
					'svn add %s',
					escapeshellarg( $path . '@' )
				);

				$commands[] = $command;

				$shell->must_run( $command );

				$changes[] = [ '➕ Added', $path ];

				break;
			default:
				throw new RuntimeException( "Unsupported working copy status: $wc_status - $path." );
		}
	}

	$core->end_group();

	/**
	 * Commit.
	 */
	$core->start_group( '⬆ Subversion commit WordPress.org' );

	$command = sprintf(
		'svn commit --message %s --non-interactive --username %s --password %s',
		escapeshellarg( 'Update' ),
		escapeshellarg( $username ),
		escapeshellarg( $password )
	);

	if ( $dry_run ) {
		$shell->skip( $command );

		$commands[] = "# Skipped (dry run): $command";
	} else {
		$commands[] = $command;

		$shell->must_run( $command );
	}

	$core->end_group();

	/**
	 * Tag.
	 */
	$core->start_group( '🏷️ Subversion tag WordPress.org' );

	$command = sprintf(
		'svn cp %s %s --message %s --non-interactive --username %s --password %s',
		escapeshellarg( "$svn_url/trunk" ),
		escapeshellarg( $svn_url_tag ),
		escapeshellarg( "Tagging version $version" ),
		escapeshellarg( $username ),
		escapeshellarg( $password )
	);

	if ( $dry_run ) {
		$shell->skip( $command );

		$commands[] = "# Skipped (dry run): $command";
	} else {
		$commands[] = $command;

		$shell->must_run( $command );
	}

	$core->end_group();

	/**
	 * Clean up.
	 */
	$core->start_group( '🗑️ Clean up' );

	$command = sprintf(
		'rm -f -R %s',
		escapeshellarg( $work_dir )
	);

	$commands[] = $command;

	$shell->must_run( $command );

	$core->end_group();
} catch ( RuntimeException $e ) {
	$error = $e->getMessage();
}

/**
 * Job summary.
 *
 * Written before `set_failed()`, because that exits.
 */
$status = match ( true ) {
	null !== $error => "❌ Failed: $error",
	$dry_run        => '🧪 Dry run, nothing committed or tagged.',
	default         => '✅ Deployed.',
};

$summary = new Summary();

$summary
	->add_heading( "Deploy $slug $version to WordPress.org" . ( $dry_run ? ' (dry run)' : '' ), 2 )
	->add_eol()
	->add_raw( $status, true )
	->add_eol()
	->add_raw( "- **GitHub release:** <$release_url>", true )
	->add_raw( "- **Plugin:** <$public_url>", true )
	->add_raw( "- **Subversion:** <$svn_url>", true )
	->add_raw( "- **Subversion tag:** <$svn_url_tag>", true )
	->add_raw( "- **Trac:** <$trac_url>", true )
	->add_eol()
	->add_heading( 'Subversion changes', 3 )
	->add_eol();

if ( [] === $changes ) {
	$summary->add_raw( 'No changes.', true );
} else {
	$summary
		->add_raw( '| Status | Path |', true )
		->add_raw( '| --- | --- |', true );

	foreach ( $changes as list( $status, $path ) ) {
		$summary->add_raw( "| $status | `$path` |", true );
	}
}

$summary
	->add_eol()
	->add_heading( 'Commands', 3 )
	->add_eol()
	->add_code_block( implode( PHP_EOL, $commands ), 'shell' )
	->add_eol()
	->add_raw( '---', true )
	->add_eol()
	->add_raw( '<sub>Deployed with [solvebeam/action-wordpress-org-plugin-deploy](https://github.com/solvebeam/action-wordpress-org-plugin-deploy), built on [solvebeam/github-actions-toolkit-php](https://github.com/solvebeam/github-actions-toolkit-php). Made with ❤️ by [SolveBeam](https://www.solvebeam.com/).</sub>', true )
	->write();

if ( null !== $error ) {
	$core->set_failed( $error );
}
