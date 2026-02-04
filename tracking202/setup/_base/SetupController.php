<?php

declare(strict_types=1);

namespace Tracking202\Setup;

use AUTH;
use User;
use Slack;

/**
 * Abstract base controller for all setup section pages.
 * Provides common functionality including authentication, permissions, 
 * user management, Slack integration, and CSRF protection.
 */
abstract class SetupController
{
    protected User $user;
    protected ?Slack $slack = null;
    protected array $errors = [];
    protected array $successMessages = [];
    protected string $csrfToken;
    
    public function __construct()
    {
        $this->requireAuthentication();
        $this->requireSetupPermission();
        $this->initializeUser();
        $this->initializeSlack();
        $this->generateCsrfToken();
    }
    
    /**
     * Main entry point for handling requests
     */
    public function handleRequest(): void
    {
        try {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $this->validateCsrfToken();
                $this->handlePost();
            } else {
                $this->handleGet();
            }
            
            $this->render();
        } catch (\Exception $e) {
            $this->handleError($e);
        }
    }
    
    /**
     * Handle GET requests - to be implemented by subclasses
     */
    abstract protected function handleGet(): void;
    
    /**
     * Handle POST requests - to be implemented by subclasses
     */
    abstract protected function handlePost(): void;
    
    /**
     * Render the page - to be implemented by subclasses
     */
    abstract protected function render(): void;
    
    /**
     * Require user authentication
     */
    private function requireAuthentication(): void
    {
        AUTH::require_user();
    }
    
    /**
     * Require setup section permissions
     */
    private function requireSetupPermission(): void
    {
        global $userObj;
        
        if (!$userObj->hasPermission("access_to_setup_section")) {
            header('location: ' . get_absolute_url() . 'tracking202/');
            die();
        }
    }
    
    /**
     * Initialize user and related data
     */
    private function initializeUser(): void
    {
        global $userObj, $db;
        
        $this->user = $userObj;
        
        // Get user data with Slack webhook
        $userId = $db->real_escape_string((string)$_SESSION['user_own_id']);
        $userSql = "SELECT 2u.user_name as username, 2u.install_hash, 
                           2up.user_slack_incoming_webhook AS url 
                    FROM 202_users AS 2u 
                    INNER JOIN 202_users_pref AS 2up ON (2up.user_id = 1) 
                    WHERE 2u.user_id = '" . $userId . "'";
        
        $userResults = $db->query($userSql);
        $this->userData = $userResults->fetch_assoc();
    }
    
    /**
     * Initialize Slack integration if configured
     */
    private function initializeSlack(): void
    {
        if (!empty($this->userData['url'])) {
            $this->slack = new Slack($this->userData['url']);
        }
    }
    
    /**
     * Generate CSRF token for forms
     */
    private function generateCsrfToken(): void
    {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        $this->csrfToken = $_SESSION['csrf_token'];
    }
    
    /**
     * Validate CSRF token from POST requests
     */
    private function validateCsrfToken(): void
    {
        $submittedToken = $_POST['csrf_token'] ?? '';
        
        if (!hash_equals($_SESSION['csrf_token'], $submittedToken)) {
            throw new \InvalidArgumentException('Invalid CSRF token');
        }
    }
    
    /**
     * Add error message
     */
    protected function addError(string $field, string $message): void
    {
        $this->errors[$field] = '<div class="error">' . htmlspecialchars($message) . '</div>';
    }
    
    /**
     * Add success message
     */
    protected function addSuccess(string $message): void
    {
        $this->successMessages[] = '<div class="success">' . htmlspecialchars($message) . '</div>';
    }
    
    /**
     * Check if there are any validation errors
     */
    protected function hasErrors(): bool
    {
        return !empty($this->errors);
    }
    
    /**
     * Get current user ID
     */
    protected function getUserId(): int
    {
        return (int)$_SESSION['user_own_id'];
    }
    
    /**
     * Get user data
     */
    protected function getUserData(): array
    {
        return $this->userData;
    }
    
    /**
     * Send Slack notification if enabled
     */
    protected function sendSlackNotification(string $event, array $data = []): void
    {
        if ($this->slack) {
            $data['user'] = $this->userData['username'];
            $this->slack->push($event, $data);
        }
    }
    
    /**
     * Redirect to a URL within the application
     */
    protected function redirect(string $path): void
    {
        header('location: ' . get_absolute_url() . $path);
        exit;
    }
    
    /**
     * Handle exceptions and errors
     */
    protected function handleError(\Exception $e): void
    {
        error_log('Setup Controller Error: ' . $e->getMessage());
        
        if ($e instanceof \InvalidArgumentException) {
            $this->addError('general', $e->getMessage());
            $this->render();
        } else {
            // For other exceptions, redirect to a safe page
            $this->redirect('tracking202/setup/');
        }
    }
    
    /**
     * Get CSRF token for forms
     */
    protected function getCsrfToken(): string
    {
        return $this->csrfToken;
    }
    
    /**
     * Render CSRF token field for forms
     */
    protected function renderCsrfField(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . 
               htmlspecialchars($this->csrfToken) . '">';
    }
    
    private array $userData;
}