<?php
declare(strict_types=1);

const LEAVE_SESSION_PREFIX = 'leave_management_session';
const LEAVE_REMEMBER_SESSION_LIFETIME = 2592000; // 30 days

function leaveSessionContext(): ?string
{
    $context = $_GET['context'] ?? $_POST['context'] ?? null;
    $allowedRoles = ['admin', 'hr', 'manager', 'employee'];

    return is_string($context) && in_array($context, $allowedRoles, true) ? $context : null;
}

function leaveSessionName(?string $context = null): string
{
    return $context === null ? LEAVE_SESSION_PREFIX : LEAVE_SESSION_PREFIX . '_' . $context;
}

function configureLeaveSession(?string $context = null, int $cookieLifetime = 0): void
{
    session_name(leaveSessionName($context));
    ini_set('session.gc_maxlifetime', (string) LEAVE_REMEMBER_SESSION_LIFETIME);
    session_set_cookie_params([
        'lifetime' => $cookieLifetime,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function startLeaveSession(?string $context = null, int $cookieLifetime = 0): void
{
    configureLeaveSession($context ?? leaveSessionContext(), $cookieLifetime);
    session_start();
}

function roleContextQuery(string $role): string
{
    return '?context=' . rawurlencode($role);
}

function leaveContextUrl(string $path): string
{
    $context = leaveSessionContext();
    if (!$context) {
        return $path;
    }

    [$basePath, $fragment] = array_pad(explode('#', $path, 2), 2, '');
    $separator = str_contains($basePath, '?') ? '&' : '?';

    return $basePath . $separator . 'context=' . rawurlencode($context) . ($fragment !== '' ? '#' . $fragment : '');
}
