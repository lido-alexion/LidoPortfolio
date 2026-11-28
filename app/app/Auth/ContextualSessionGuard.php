<?php

namespace App\Auth;

use Illuminate\Auth\SessionGuard;

class ContextualSessionGuard extends SessionGuard
{
    /**
     * Keep Laravel's existing investor session key so sessions issued by the
     * framework guard remain readable after the guard is upgraded.
     */
    public function getName()
    {
        if ($this->request->attributes->get('auth_session_context', 'investor') !== 'admin') {
            return 'login_'.$this->name.'_'.sha1(SessionGuard::class);
        }

        return parent::getName();
    }

    public function getRecallerName()
    {
        $context = $this->request->attributes->get('auth_session_context', 'investor');

        // Keep the exact Laravel SessionGuard recaller cookie for existing
        // investor remember-me sessions; only the new admin context is namespaced.
        $guardClass = $context === 'admin' ? static::class : \Illuminate\Auth\SessionGuard::class;

        return $context === 'admin'
            ? 'remember_admin_'.$this->name.'_'.sha1($guardClass)
            : 'remember_'.$this->name.'_'.sha1($guardClass);
    }
}
