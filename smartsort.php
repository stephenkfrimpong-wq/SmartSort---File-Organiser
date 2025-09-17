#!/usr/bin/env php
<?php
/**
 * SmartSort - Automated File Organisation System
 * A command-line tool to organise files by type, date, or custom rules
 * @author Your Name
 * @github https://github.com/yourusername/smartsort
 */

// Error reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Define version and default categories
const VERSION = '1.0.0';
const DEFAULT_CATEGORIES = [
    'images' => ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'svg', 'webp'],
    'documents' => ['pdf', 'doc', 'docx', 'txt', 'rtf', 'xls', 'xlsx', 'ppt', 'pptx'],
    'archives' => ['zip', 'rar', '7z', 'tar', 'gz'],
    'code' => ['php', 'js', 'html', 'css', 'py', 'java', 'cpp', 'json'],
    'audio' => ['mp3', 'wav', 'flac', 'aac'],
    'video' => ['mp4', 'avi', 'mov', 'wmv', 'flv'],
    'other' => [] // Catch-all category
];

class SmartSort {
    private $config = [];
    private $log = [];
    private $dryRun = false;
    private $interactive = false;

    public function __construct() {
        $this->loadConfig();
    }

    /**
     * Load configuration from config.json if exists
     */
    private function loadConfig() {
        $configFile = __DIR__ . '/config.json';
        if (file_exists($configFile)) {
            $this->config = json_decode(file_get_contents($configFile), true);
        } else {
            $this->config = [
                'categories' => DEFAULT_CATEGORIES,
                'date_format' => 'Y-m',
                'log_file' => 'smartsort.log'
            ];
        }
    }

    /**
     * Main execution method
     */
    public function run($options) {
        $path = $options['path'] ?? null;
        $this->dryRun = isset($options['dry-run']);
        $this->interactive = isset($options['interactive']);

        // Validate path
        if (!$path || !is_dir($path)) {
            $this->output("Error: Invalid or missing directory path.", 'error');
            $this->showUsage();
            exit(1);
        }

        $path = realpath($path);
        $this->output("SmartSort v" . VERSION . " - Scanning: $path");
        $this->output("Mode: " . ($this->dryRun ? "Dry Run" : "Live"));

        if ($this->interactive) {
            $this->output("Interactive mode: enabled");
            if (!$this->confirm("Continue with organisation?")) {
                $this->output("Operation cancelled.");
                exit(0);
            }
        }

        // Scan and organise
        $this->organiseDirectory($path);

        // Show summary
        $this->showSummary();

        // Write log
        if (!$this->dryRun) {
            $this->writeLog();
        }
    }

    /**
     * Recursively organise directory
     */
    private function organiseDirectory($dirPath) {
        $items = array_diff(scandir($dirPath), ['.', '..']);
        
        foreach ($items as $item) {
            $fullPath = $dirPath . DIRECTORY_SEPARATOR . $item;
            
            if (is_dir($fullPath)) {
                $this->organiseDirectory($fullPath); // Recurse into subdirectories
                continue;
            }

            $this->processFile($fullPath);
        }
    }

    /**
     * Process individual file
     */
    private function processFile($filePath) {
        $fileInfo = pathinfo($filePath);
        $extension = strtolower($fileInfo['extension'] ?? '');
        $fileName = $fileInfo['filename'];
        $fileDir = $fileInfo['dirname'];

        // Determine category
        $category = $this->getCategory($extension);
        $dateCategory = $this->getDateCategory($filePath);

        // Build destination path
        $destDir = $fileDir . DIRECTORY_SEPARATOR . $category;
        
        if ($dateCategory) {
            $destDir .= DIRECTORY_SEPARATOR . $dateCategory;
        }

        $destPath = $destDir . DIRECTORY_SEPARATOR . basename($filePath);

        // Skip if already in correct location
        if ($filePath === $destPath) {
            return;
        }

        // Create destination directory if needed
        if (!file_exists($destDir) && !$this->dryRun) {
            mkdir($destDir, 0755, true);
        }

        // Move file
        $this->moveFile($filePath, $destPath);
    }

    /**
     * Get category for file extension
     */
    private function getCategory($extension) {
        foreach ($this->config['categories'] as $category => $extensions) {
            if (in_array($extension, $extensions)) {
                return $category;
            }
        }
        return 'other';
    }

    /**
     * Get date-based category
     */
    private function getDateCategory($filePath) {
        if (isset($this->config['date_organisation']) && $this->config['date_organisation']) {
            $timestamp = filemtime($filePath);
            $format = $this->config['date_format'] ?? 'Y-m';
            return date($format, $timestamp);
        }
        return null;
    }

    /**
     * Move file with error handling
     */
    private function moveFile($source, $destination) {
        $logEntry = [
            'from' => $source,
            'to' => $destination,
            'success' => false,
            'error' => ''
        ];

        try {
            if ($this->dryRun) {
                $logEntry['action'] = 'dry_run';
                $this->output("[DRY RUN] Would move: " . basename($source) . " → " . dirname($destination));
            } else {
                if (rename($source, $destination)) {
                    $logEntry['action'] = 'moved';
                    $logEntry['success'] = true;
                    $this->output("Moved: " . basename($source) . " → " . dirname($destination));
                } else {
                    throw new Exception("File move operation failed");
                }
            }
        } catch (Exception $e) {
            $logEntry['error'] = $e->getMessage();
            $this->output("Error moving " . basename($source) . ": " . $e->getMessage(), 'error');
        }

        $this->log[] = $logEntry;
    }

    /**
     * Show operation summary
     */
    private function showSummary() {
        $moved = array_filter($this->log, fn($entry) => $entry['success'] ?? false);
        $errors = array_filter($this->log, fn($entry) => !($entry['success'] ?? true));

        $this->output("\n" . str_repeat('=', 50));
        $this->output("SUMMARY:");
        $this->output("Files processed: " . count($this->log));
        $this->output("Successfully moved: " . count($moved));
        $this->output("Errors: " . count($errors));
        
        if (count($errors) > 0) {
            $this->output("\nErrors:", 'warning');
            foreach ($errors as $error) {
                $this->output(" - " . $error['from'] . ": " . $error['error'], 'warning');
            }
        }
    }

    /**
     * Write log to file
     */
    private function writeLog() {
        $logFile = $this->config['log_file'] ?? 'smartsort.log';
        $logContent = date('Y-m-d H:i:s') . " - SmartSort Operation\n";
        $logContent .= json_encode($this->log, JSON_PRETTY_PRINT) . "\n\n";
        
        file_put_contents($logFile, $logContent, FILE_APPEND);
        $this->output("Log written to: " . $logFile);
    }

    /**
     * Interactive confirmation
     */
    private function confirm($message) {
        echo $message . " (y/N): ";
        $response = strtolower(trim(fgets(STDIN)));
        return $response === 'y' || $response === 'yes';
    }

    /**
     * Colored output
     */
    private function output($message, $type = 'info') {
        $colors = [
            'info' => '0;32', // Green
            'error' => '0;31', // Red
            'warning' => '0;33' // Yellow
        ];

        $color = $colors[$type] ?? '0;37';
        echo "\033[{$color}m{$message}\033[0m\n";
    }

    /**
     * Show usage instructions
     */
    private function showUsage() {
        $this->output("\nUSAGE:", 'info');
        $this->output("  php smartsort.php --path=/path/to/dir [options]");
        $this->output("\nOPTIONS:", 'info');
        $this->output("  --path          Path to directory to organise (required)");
        $this->output("  --dry-run       Preview changes without moving files");
        $this->output("  --interactive   Confirm actions before processing");
        $this->output("  --help          Show this help message");
        $this->output("\nEXAMPLES:", 'info');
        $this->output("  php smartsort.php --path=./downloads");
        $this->output("  php smartsort.php --path=/home/user/Desktop --dry-run");
        $this->output("  php smartsort.php --path=./projects --interactive");
    }
}

// Parse command line arguments
$options = getopt('', ['path:', 'dry-run', 'interactive', 'help']);

if (isset($options['help'])) {
    $organiser = new SmartSort();
    $organiser->showUsage();
    exit(0);
}

// Run the application
try {
    $organiser = new SmartSort();
    $organiser->run($options);
} catch (Exception $e) {
    echo "\033[0;31mError: " . $e->getMessage() . "\033[0m\n";
    exit(1);
}
