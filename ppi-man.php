<?php

/**
 * ppi-man
 * 
 * First of all: THIS IS AN ALPHA VERSION. DO NOT USE ON PRODUCTION!
 * 
 * Allows you to uninstall a Tiny Core Linux PPI extension along
 * with its dependencies. To use it, boot in TCE mode.
 * 
 * Copyright (c) 2010-2011, Albert Almeida (caviola@gmail.com)
 * All rights reserved.
 * 
 * THE SOFTWARE IS PROVIDED 'AS IS', WITHOUT WARRANTY OF ANY KIND,
 * EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
 * MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
 * IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY
 * CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT,
 * TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE
 * SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 * 
 */
 
error_reporting(E_ALL ^ E_NOTICE);

function abort($message) {
    fputs(STDERR, $message);
    exit(1);
}

function usage() {
    echo <<<MSG

Usage: 
    php ppi-man.php option [extension]

Options:    
    --installed, i   Show list of installed extensions.
    --dep, -d        Show extension dependency information.
    --files, -f      Show extension's file list.
    --uninstall, -u  Removes all files associated with the extension.
    --rebuild, -r    Rebuild all databases.
    --tree, -t       Prints an extension's full dependency tree.
    --not-used, -n   Print a list of extensions not used by anyone.

MSG;

    exit(0);
}

function _get_tce_name($ext) {
    if (!$ext)
        abort("Extension name expected.\n");
    if (!preg_match('/.tcz$/', $ext))
        $ext .= '.tcz';
    return $ext;
}

function tce_repo_get($filename) {
    global $conf;
    
    $localfile = "$conf->cache_dir/$filename";
    
    // Download file if not already cached.
    $filesize = @filesize($localfile);
    if ($filesize === FALSE) {
        $data = @file_get_contents("$conf->mirror/$filename");
        @file_put_contents($localfile, $data);
        if (!$data) 
            return FALSE;
    } elseif ($filesize === 0) {
        // File exists but it's zero-size which means the extension
        // has no dependencies.
        return FALSE;
    }
    
    $lines = array();
    $fh = fopen($localfile, 'r');
    while (($s = fgets($fh)) !== FALSE) {
        $s = trim($s);
        if ($s) $lines[] = $s;
    }
    fclose($fh);
    
    return $lines;
}

function tce_dep($extname) {
    return tce_repo_get("$extname.dep");
}

function tce_files($extname) {
    return tce_repo_get("$extname.list");
}

function print_dependency($ext) {
    global $db;
    
    $depends_on = $db->dependency[$ext];
    if ($depends_on) {
        echo "The extension $ext depends on:\n";
        print_ext_list($depends_on);
        echo "\n";
    } else {
        echo "The extension $ext has no dependencies.\n";
    }
}

function print_used_by($ext) {
    global $db;
    
    $used_by = $db->reverse_dependency[$ext];
    
    if ($used_by) {
        echo "The extension $ext is used by:\n";
        print_ext_list($used_by);
        echo "\n";
    } else {
        echo "No extension uses $ext.\n";
    }
}


function print_ext_files($ext) {
    $files = tce_files($ext);
    echo "The extension $ext contains the following files:\n";
    foreach ($files as $file) {
        echo "  $file\n";
    }
}


function update_installed_ext_list() {
    global $conf, $db;
    
    $db->extension_list = array();
    
    echo "Updating installed extension list...\n";
    
    if (file_exists($conf->extension_file)) {
        echo "  reading from file $conf->extension_file...";
        
        $fh = fopen($conf->extension_file, 'r');
        while (($line = fgets($fh)) !== FALSE) {
            $db->extension_list[] = trim($line);
        }
        fclose($fh);
        
        echo count($db->extension_list) . " found.\n";
    } elseif (is_dir($conf->extension_dir)) {
        echo "  reading from directory $conf->extension_dir...";
        
        $db->extension_list = scandir($conf->extension_dir);
        
        // Remove the '.' and '..' directories.
        unset($db->extension_list[0]);
        unset($db->extension_list[1]);
        
        sort($db->extension_list);
        
        // Append .tcz to each extension name.
        for ($i = 0; $i < count($db->extension_list); $i++) {
            $db->extension_list[$i] .= '.tcz';
        }
        
        echo count($db->extension_list) . " found.\n";
    } else {
        abort("Error reading installed extension list.\n");
    }
    
    if (@file_put_contents($conf->cache_dir . '/tce.installed', serialize($db->extension_list)) === FALSE) {
        abort("Error writing to file 'tce.installed'.\n");
    }
}

function load_installed_ext_list() {
    global $conf, $db;
    
    if (!file_exists($conf->cache_dir . '/tce.installed')) 
        abort("Could not find file 'tce.installed'.\nTry rebuilding the database with:\n  php ppi-man.php --rebuild\n");
        
    if (($content = @file_get_contents($conf->cache_dir . '/tce.installed')) === FALSE)
        abort("Error reading file 'tce.installed'.\n");
        
    $db->extension_list = unserialize($content);
}

function load_dep_db() {
    global $db, $conf;
    
    if (!file_exists($conf->cache_dir . '/tce.dep'))
        abort("Could not find file 'tce.dep'.\nTry rebuilding the database with:\n  php ppi-man.php --rebuild\n");
    
    if (($content = @file_get_contents($conf->cache_dir . '/tce.dep')) === FALSE)
        abort("Error reading from 'tce.dep'.\n");
        
    $db->dependency = unserialize($content);
}

function load_reverse_dep_db() {
    global $db, $conf;
    
    if (!file_exists($conf->cache_dir . '/tce.reverse-dep'))
        abort("Could not find file 'tce.revese-dep'.\nTry rebuilding the database with:\n  php ppi-man.php --rebuild\n");
    
    if (($content = @file_get_contents($conf->cache_dir . '/tce.reverse-dep')) === FALSE)
        abort("Error reading from 'tce.reverse-dep'.\n");
        
    $db->reverse_dependency = unserialize($content);
}

function update_ext_dep() {
    global $db, $conf;
    
    if (!$db->extension_list)
        abort("Nothing to do. The installed extension list is empty.\n");
    
    echo 'Updating dependencies...';
    $db->dependency = array();
    
    foreach ($db->extension_list as $ext) {
        $dep = tce_dep($ext);
        if ($dep) {
            $db->dependency[$ext] = $dep;
        }
    }
    
    echo "Done.\n";
    
    if ((@file_put_contents($conf->cache_dir . '/tce.dep', serialize($db->dependency))) === FALSE)
        abort("Error writing to file 'tce.dep'.\n");
}

function update_ext_reverse_dep() {
    global $db, $conf;
    
    echo 'Updating reverse dependencies...';
    $db->reverse_dependency = array();
    
    foreach ($db->extension_list as $ext) {
        $dep_list = tce_dep($ext);
        if ($dep_list) {
            foreach ($dep_list as $dep) 
                $db->reverse_dependency[$dep][] = $ext;
        }
    }
    
    echo "Done.\n";
    
    if ((@file_put_contents($conf->cache_dir . '/tce.reverse-dep', serialize($db->reverse_dependency))) === FALSE)
        abort("Error writing to file 'tce.reverse-dep'.\n");
}

function get_dependency_tree($ext, &$full_dep, &$visited, $depth = 0) {
    global $db;
    
    $dep = $db->dependency[$ext];
    if ($dep) {
        foreach ($dep as $d)
            if (!isset($visited[$d])) 
                get_dependency_tree($d, $full_dep, $visited, $depth + 1);
    }
    
    $visited[$ext] = 1;
    $full_dep[] = (object)array('name' => $ext, 'depth' => $depth);
}

function print_dependency_tree($ext, $pad = '', $depth = 0) {
    global $db;
    static $visited = array();
    
    echo $pad . str_repeat('   ', $depth) . "$ext ($depth)\n";
    
    if (isset($visited[$ext]))
        return;
    
    $dep = $db->dependency[$ext];
    if ($dep) {
        foreach ($dep as $d)
            print_dependency_tree($d, $pad, $depth + 1);
    }
    $visited[$ext] = TRUE;
}


function full_uninstall_ext($ext) {
    global $db;
    
    $used_by = $db->reverse_dependency[$ext];
    if ($used_by) {
        echo "Can't uninstall $ext becuase it's used by:\n";
        print_ext_list($used_by);
        return;
    }
    
    // Get the extension's full dependency tree topologically sorted.
    // The result is a list where each element contains an extension name 
    // and its "depth" in the tree.
    // Each extension appears in the list after all its dependencies.
    // The dependency map contains the same extensions in the 
    // dependency tree in the same order but without "depth".
    $dependency_tree = array();
    $dependency_map = array();
    get_dependency_tree($ext, $dependency_tree, $dependency_map);
    
    for ($i = count($dependency_tree) - 1; $dep = $dependency_tree[$i]; --$i) {
        $dep_used_by = $db->reverse_dependency[$dep->name];
        
        if ($dep_used_by) {
            foreach ($dep_used_by as $d) {
                // If $dep is used by an extension not in the dependency tree
                // we can't remove neither it nor its dependencies.
                if (!isset($dependency_map[$d])) {
                    unset($dependency_map[$dep->name]);
                    // Skip all dependencies of $dep.
                    // They are located to the left of $dep inside $dependency_tree 
                    // and all have a depth greater than $dep->depth.
                    while (($i - 1) >= 0 && $dependency_tree[$i - 1]->depth > $dep->depth) {
                        unset($dependency_map[$dependency_tree[$i - 1]->name]);
                        --$i;
                    }
                    break;
                }
            }
        }
    }

    $dependency_map = array_keys($dependency_map);
    echo "The following extensions will be uninstalled:\n";
    print_ext_list($dependency_map);
    
    echo "\nAre you sure you want to continue (y/n): ";
    $answer = trim(fgets(STDIN));
    if ($answer == 'y') {
        foreach ($dependency_map as $d) 
            _uninstall_ext($d);
    }
    echo "\n";
}                                                     

function _uninstall_ext($ext) {
    global $conf;
    
    echo "  Uninstalling $ext...";
    $files = tce_files($ext);
    if ($files) {
        foreach ($files as $f) {
            $filepath = preg_replace('#^usr/local#i', $conf->tclocal, $f, -1, $count);
            if ($count) {
                // Try deleting the file.
                // Abort if there is an error deleting an existing file.
                if (!@unlink($filepath) && file_exists($filepath))
                    abort("Error deleting $filepath\n");
            }
        }
    }
    @unlink($conf->tclocal . '/tce.installed/' . substr($ext, 0, -4/*remove trailing .tcz*/));
    echo "Done.\n";
}

function update_database() {
    update_installed_ext_list();
    update_ext_dep();
    update_ext_reverse_dep();
}

function print_not_used_extensions() {
    global $db;
    
    echo "Extensions not used by anyone:\n";
    foreach ($db->extension_list as $ext) {
        $used_by = $db->reverse_dependency[$ext];
        if (!$used_by) {
            echo "  $ext\n";
        }
    }
}

function print_ext_list($exts) {
    foreach ($exts as $e) {
        echo "  $e\n";
    }
}

if ($argc < 2)
    usage();

$tc_cmdline = @file_get_contents('/proc/cmdline');

// Read configuration options.    
$ini = parse_ini_file(dirname(__FILE__) . '/ppi-man.ini');

$conf = new stdClass();
$conf->mirror = $ini['mirror'];

$conf->cache_dir = $ini['cache_dir'];
if (!$conf->cache_dir) {
    $conf->cache_dir = '/tmp';
} elseif (!is_dir($conf->cache_dir)) {
    mkdir($conf->cache_dir, 0777, TRUE);
}

$conf->extension_file = $ini['extension_file'];
$conf->extension_dir = $ini['extension_dir'];
$conf->tclocal = $ini['tclocal'];
$conf->dep_file = $conf->cache_dir . '/tce.dep';
$conf->reverse_dep_file = $conf->cache_dir . '/tce.reverse-dep';

$db = new stdClass();

echo "\nCache directory set to $conf->cache_dir.\n\n";

switch ($argv[1]) {
    case '--installed':
    case '-i':
        load_installed_ext_list();
        echo "Installed extensions:\n";
        print_ext_list($db->extension_list);
        break;
    
    case '--dep':
    case '-d':
        $ext = _get_tce_name($argv[2]);
        load_dep_db();
        load_reverse_dep_db();
        print_used_by($ext);
        print_dependency($ext);
        break;
        
    case '--tree':
    case '-t':
        $ext = _get_tce_name($argv[2]);
        load_dep_db();
        echo "Dependency tree for $ext:\n";
        print_dependency_tree($ext, '  ');
        break;
        
        
    case '--files':
    case '-f':
        $ext = _get_tce_name($argv[2]);
        print_ext_files($ext);
        break;
        
    case '--uninstall':
    case '-u':
        $ext = _get_tce_name($argv[2]);
        load_dep_db();
        load_reverse_dep_db();
        full_uninstall_ext($ext);
        update_database();
        break;

    case '--rebuild':
    case '-r':
        update_database();
        break;
        
    case '--not-used':
    case '-n':
        load_installed_ext_list();
        load_reverse_dep_db();
        print_not_used_extensions();
        break;
}

echo "\n";
