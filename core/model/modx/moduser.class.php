<?php
/**
 * @package modx
 */
/**
 * The core MODX user class.
 *
 * @property string $username The username for this User
 * @property string $password The encrypted password for this User
 * @property string $cachepwd A cached, encrypted password used when resetting the User's password or for confirmation
 * @property string $class_key The class key of the user. Used for extending the modUser class.
 * @property boolean $active Whether or not this user is active, and thereby able to log in
 * @property string $remote_key Used for storing a remote reference key for authentication for a User
 * @property json $remote_data Used for storing remote data for authentication for a User
 * @property string $hash_class The hashing class used to create this User's password
 * @property string $salt A salt that might have been used to create this User's password
 *
 * @property modUserProfile $Profile
 * @property modUserGroup $PrimaryGroup
 * @property array $CreatedResources
 * @property array $EditedResources
 * @property array $DeletedResources
 * @property array $PublishedResources
 * @property array $SentMessages
 * @property array $ReceivedMessages
 * @property array $UserSettings
 * @property array $UserGroupMembers
 *
 * @see modUserGroupMember
 * @see modUserGroupRole
 * @see modUserMessage
 * @see modUserProfile
 * @package modx
 */
class modUser extends modPrincipal {
    /**
     * A collection of contexts which the current principal is authenticated in.
     * @var array
     * @access public
     */
    public $sessionContexts= array ();

    /**
     * The modUser password field is hashed automatically.
     *
     * {@inheritdoc}
     */
    public function set($k, $v= null, $vType= '') {
        if (in_array($k, array('password', 'cachepwd')) && $this->xpdo->getService('hashing', 'hashing.modHashing')) {
            if (!$this->get('salt')) {
                $this->set('salt', md5(uniqid(rand(),true)));
            }
            $vOptions = array('salt' => $this->get('salt'));
            $v = $this->xpdo->hashing->getHash('', $this->get('hash_class'))->hash($v, $vOptions);
        }
        return parent::set($k, $v, $vType);
    }

    /**
     * Overrides xPDOObject::save to fire modX-specific events
     * 
     * {@inheritDoc}
     */
    public function save($cacheFlag = false) {
        $isNew = $this->isNew();

        if ($this->xpdo instanceof modX) {
            $this->xpdo->invokeEvent('OnUserBeforeSave',array(
                'mode' => $isNew ? modSystemEvent::MODE_NEW : modSystemEvent::MODE_UPD,
                'user' => &$this,
                'cacheFlag' => $cacheFlag,
            ));
        }

        $saved = parent :: save($cacheFlag);
        
        if ($saved && $this->xpdo instanceof modX) {
            $this->xpdo->invokeEvent('OnUserSave',array(
                'mode' => $isNew ? modSystemEvent::MODE_NEW : modSystemEvent::MODE_UPD,
                'user' => &$this,
                'cacheFlag' => $cacheFlag,
            ));
        }
        return $saved;
    }

    /**
     * Overrides xPDOObject::remove to fire modX-specific events
     *
     * {@inheritDoc}
     */
    public function remove(array $ancestors = array()) {
        if ($this->xpdo instanceof modX) {
            $this->xpdo->invokeEvent('OnUserBeforeRemove',array(
                'user' => &$this,
                'ancestors' => $ancestors,
            ));
        }

        $removed = parent :: remove($ancestors);

        if ($this->xpdo instanceof modX) {
            $this->xpdo->invokeEvent('OnUserRemove',array(
                'user' => &$this,
                'ancestors' => $ancestors,
            ));
        }

        return $removed;
    }

    /**
     * Loads the principal attributes that define a modUser security profile.
     *
     * {@inheritdoc}
     */
    public function loadAttributes($target, $context = '', $reload = false) {
        $context = !empty($context) ? $context : $this->xpdo->context->get('key');
        $id = $this->get('id') ? (string) $this->get('id') : '0';
        if ($this->get('id') && !$reload) {
            $staleContexts = $this->get('session_stale');
            $staleContexts = !empty($staleContexts) ? $staleContexts : array();
            $stale = array_search($context, $staleContexts);
            if ($stale !== false) {
                $reload = true;
                $staleContexts = array_diff($staleContexts, array($context));
                $this->set('session_stale', $staleContexts);
                $this->save();
            }
        }
        if ($this->_attributes === null || $reload) {
            $this->_attributes = array();
            if (isset($_SESSION["modx.user.{$id}.attributes"])) {
                if ($reload) {
                    unset($_SESSION["modx.user.{$id}.attributes"]);
                } else {
                    $this->_attributes = $_SESSION["modx.user.{$id}.attributes"];
                }
            }
        }
        if (!isset($this->_attributes[$context])) {
            $this->_attributes[$context] = array();
        }
        $target = (array) $target;
        foreach ($target as $t) {
            if (!isset($this->_attributes[$context][$t])) {
                $this->_attributes[$context][$t] = $this->xpdo->call(
                    $t,
                    'loadAttributes',
                    array(&$this->xpdo, $context, $this->get('id'))
                );
                if (!isset($this->_attributes[$context][$t]) || !is_array($this->_attributes[$context][$t])) {
                    $this->_attributes[$context][$t] = array();
                }
            }
        }
        $_SESSION["modx.user.{$id}.attributes"] = $this->_attributes;
    }

    /**
     * Determines if this user is authenticated in a specific context.
     *
     * Separate session contexts can allow users to login/out of specific sub-sites
     * individually (or in collections).
     *
     * @access public
     * @param string $sessionContext The context to determine if the user is
     * authenticated in.
     * @return boolean true, if the user is authenticated in the specified
     * context, false otherwise.
     */
    public function isAuthenticated($sessionContext= 'web') {
        $isAuthenticated= false;
        if (!empty ($sessionContext) && is_string($sessionContext)) {
            if ($this->hasSessionContext($sessionContext)) {
                $isAuthenticated= true;
            }
            elseif (isset ($_SESSION[$sessionContext . "Validated"])) {
                $isAuthenticated= ($_SESSION[$sessionContext . "Validated"] == 1);
            }
        }
        return $isAuthenticated;
    }

    /**
     * Ends a user session completely, including all contexts.
     *
     * @access public
     */
    public function endSession() {
        $this->removeLocks();
        $_SESSION = array();
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }
        session_destroy();
    }

    /**
     * Determines if the provided password matches the hashed password stored for the user.
     *
     * @param string $password The password to determine if it matches.
     * @param array $options Optional settings for the hashing process.
     * @return boolean True if the provided password matches the stored password for the user.
     */
    public function passwordMatches($password, array $options = array()) {
        $match = false;
        if ($this->xpdo->getService('hashing', 'hashing.modHashing')) {
            $options = array_merge(array('salt' => $this->get('salt')), $options);
            $hashedPassword = $this->xpdo->hashing->getHash('', $this->get('hash_class'))->hash($password, $options);
            $match = ($this->get('password') === $hashedPassword);
        }
        return $match;
    }

    /**
     * Activate a reset user password if the proper activation key is provided.
     *
     * {@internal This does not mark the user active, but rather moves the cachepwd to the
     * password field if the activation key matches.}
     *
     * @param string $key The activation key provided to the user and stored in the registry for matching.
     * @return boolean|integer True if the activation was successful, false if unsuccessful,
     * and -1 if there is no activation to perform.
     */
    public function activatePassword($key) {
        $activated = -1;
        if ($this->get('cachepwd')) {
            if ($this->xpdo->getService('registry', 'registry.modRegistry') && $this->xpdo->registry->getRegister('user', 'registry.modDbRegister')) {
                if ($this->xpdo->registry->user->connect()) {
                    $activated = false;
                    $this->xpdo->registry->user->subscribe('/pwd/reset/' . md5($this->get('username')));
                    $msgs = $this->xpdo->registry->user->read(array('poll_limit' => 1));
                    if (!empty($msgs)) {
                        if ($key === reset($msgs)) {
                            $this->_setRaw('password', $this->get('cachepwd'));
                            $this->_setRaw('cachepwd', '');
                            $activated = $this->save();
                        }
                    }
                }
            }
            if ($activated === false) {
                $this->_setRaw('cachepwd', '');
                $this->save();
            }
        }
        return $activated;
    }

    /**
     * Change the user password.
     *
     * @access public
     * @param string $newPassword Password to set.
     * @param string $oldPassword Current password for validation.
     * @return boolean Indicates if password was successfully changed.
     * @todo Add support for configurable password encoding.
     */
    public function changePassword($newPassword, $oldPassword) {
        $changed= false;
        if ($this->passwordMatches($oldPassword)) {
            if (!empty ($newPassword)) {
                $this->set('password', $newPassword);
                $changed= $this->save();
                if ($changed) {
                    $this->xpdo->invokeEvent('OnUserChangePassword', array (
                        'user' => &$this,
                        'newpassword' => $newPassword,
                        'oldpassword' => $oldPassword,
                        'userid' => $this->get('id'),/* deprecated */
                        'username' => $this->get('username'),/* deprecated */
                        'userpassword' => $newPassword,/* deprecated */
                    ));
                }
            }
        }
        return $changed;
    }

    /**
     * Returns an array of user session context keys.
     *
     * @access public
     * @return array An array of session contexts.
     */
    public function getSessionContexts() {
        if (!is_array($this->sessionContexts) || empty ($this->sessionContexts)) {
            $this->sessionContexts= array ();
            if (isset ($_SESSION['modx.user.contextTokens'])) {
                $this->sessionContexts= $_SESSION['modx.user.contextTokens'];
            } else {
                $legacyContextTokens= array ();
                if (isset ($_SESSION["webValidated"]) && $_SESSION["webValidated"] == 1) {
                    $legacyContextTokens[]= 'web';
                }
                if (isset ($_SESSION["mgrValidated"]) && $_SESSION["mgrValidated"] == 1) {
                    $legacyContextTokens[]= 'mgr';
                }
                foreach ($legacyContextTokens as $token)
                    $this->addSessionContext($token);
            }
            $_SESSION['modx.user.contextTokens']= $this->sessionContexts;
        }
        return $this->sessionContexts;
    }

    /**
     * Adds a new context to the user session context array.
     *
     * @access public
     * @param string $context The context to add to the user session.
     */
    public function addSessionContext($context) {
        if (!empty($context)) {
            $this->getSessionContexts();
            session_regenerate_id(true);

            $this->getOne('Profile');
            if ($this->Profile && $this->Profile instanceof modUserProfile) {
                $ua= & $this->Profile;
                if ($ua && !isset ($this->sessionContexts[$context]) || $this->sessionContexts[$context] != $this->get('id')) {
                    $ua->set('failedlogincount', 0);
                    $ua->set('logincount', $ua->logincount + 1);
                    $ua->set('lastlogin', $ua->thislogin);
                    $ua->set('thislogin', time());
                    $ua->set('sessionid', session_id());
                    $ua->save();
                }
            }
            $this->sessionContexts[$context]= $this->get('id');
            $_SESSION['modx.user.contextTokens']= $this->sessionContexts;
            if (!isset($_SESSION["modx.{$context}.user.token"])) {
                $_SESSION["modx.{$context}.user.token"]= $this->generateToken($context);
            }
        } else {
            $this->xpdo->log(xPDO::LOG_LEVEL_ERROR, "Attempt to login to a context with an empty key");
        }
    }

    /**
     * Generate a specific authentication token for this user for accessing the MODX manager
     * @param string $salt Ignored
     * @return string
     */
    public function generateToken($salt) {
        return uniqid($this->xpdo->site_id . '_' . $this->get('id'), true);
    }

    /**
     * Get the user token for the user
     * @param string $ctx
     * @return string
     */
    public function getUserToken($ctx = '') {
        if (empty($ctx)) $ctx = $this->xpdo->context->get('key');
        return isset($_SESSION['modx.'.$ctx.'.user.token']) ? $_SESSION['modx.'.$ctx.'.user.token'] : '';
    }

    /**
     * Removes a user session context.
     *
     * @access public
     * @param string|array $context The context key or an array of context keys.
     */
    public function removeSessionContext($context) {
        if ($this->getSessionContexts()) {
            $contextToken= array ();
            if (is_array($context)) {
                foreach ($context as $ctx) {
                    $contextToken[$ctx]= $this->get('id');
                    $this->removeSessionContextVars($ctx);
                }
            } else {
                $contextToken[$context]= $this->get('id');
                $this->removeSessionContextVars($context);
            }
            $this->sessionContexts= array_diff_assoc($this->sessionContexts, $contextToken);
            if (empty($this->sessionContexts)) {
                $this->endSession();
            } else {
                $_SESSION['modx.user.contextTokens']= $this->sessionContexts;
            }
        }
    }

    /**
     * Removes the session vars associated with a specific context.
     *
     * @access public
     * @param string $context The context key.
     */
    public function removeSessionContextVars($context) {
        if (is_string($context) && !empty ($context)) {
            unset($_SESSION["modx.{$context}.user.token"]);
            unset($_SESSION["modx.{$context}.user.config"]);
            unset($_SESSION["modx.{$context}.session.cookie.lifetime"]);
        }
    }

    /**
     * Removes a session cookie for a user.
     *
     * TODO Implement this.
     *
     * @access public
     * @param string $context The context to remove.
     */
    public function removeSessionCookie($context) {}

    /**
     * Checks if the user has a specific session context.
     *
     * @access public
     * @param mixed $context Either a name of a context or array of context
     * names to check against.
     * @return boolean True if the user has the context(s) specified.
     */
    public function hasSessionContext($context) {
        $hasContext= false;
        if ($this->getSessionContexts()) {
            $contextTokens= array ();
            if (is_array($context)) {
                foreach ($context as $ctx) {
                    $contextTokens[$ctx]= $this->get('id');
                }
            }
            elseif (is_string($context)) {
                $contextTokens[$context]= $this->get('id');
            }
            $hasContext= (count(array_intersect_assoc($contextTokens, $this->sessionContexts)) == count($contextTokens));
        }
        return $hasContext;
    }

    /**
     * Gets a count of {@link modUserMessage} objects ascribed to the user.
     *
     * @access public
     * @param mixed $read
     * @return integer The number of messages.
     */
    public function countMessages($read = '') {
        if ($read == 'read') { $read = 1; } elseif ($read == 'unread') { $read = 0; }
        $criteria= array ('recipient' => $this->get('id'));
        if ($read) {
            $criteria['messageread']= $read;
        }
        return $this->xpdo->getCount('modUserMessage', $criteria);
    }

    /**
     * Gets all user settings in array format.
     *
     * @access public
     * @return array A key -> value array of settings.
     */
    public function getSettings() {
        $settings = array();
        $uss = $this->getMany('UserSettings');
        /** @var modUserSetting $us */
        foreach ($uss as $us) {
            $settings[$us->get('key')] = $us->get('value');
        }
        $this->settings = $settings;
        return $settings;
    }

    /**
     * Gets all Resource Groups this user is assigned to. This may not work in
     * the new model.
     *
     * @deprecated
     * @todo refactor this to actually work.
     * @access public
     * @param string $ctx The context in which to peruse for Resource Groups
     * @return array An array of Resource Group names.
     */
    public function getResourceGroups($ctx = '') {
        if (empty($ctx)) $ctx = $this->xpdo->context->get('key');
        $resourceGroups= array ();
        $id = $this->get('id') ? (string) $this->get('id') : '0';
        if (isset($_SESSION["modx.user.{$id}.resourceGroups"][$ctx])) {
            $resourceGroups= $_SESSION["modx.user.{$id}.resourceGroups"][$ctx];
        } else {
            $this->loadAttributes('modAccessResourceGroup',$ctx,true);
            if (isset($_SESSION["modx.user.{$id}.resourceGroups"][$ctx])) {
                $resourceGroups= $_SESSION["modx.user.{$id}.resourceGroups"][$ctx];
            }
        }
        return $resourceGroups;
    }

    /**
     * Gets all the User Group IDs of the groups this user belongs to.
     *
     * @access public
     * @return array An array of User Group IDs.
     */
    public function getUserGroups() {
        $groups= array();
        $id = $this->get('id') ? (string) $this->get('id') : '0';
        if (isset($_SESSION["modx.user.{$id}.userGroups"])) {
            $groups= $_SESSION["modx.user.{$id}.userGroups"];
        } else {
            $memberGroups= $this->xpdo->getCollectionGraph('modUserGroup', '{"UserGroupMembers":{}}', array('UserGroupMembers.member' => $this->get('id')));
            if ($memberGroups) {
                foreach ($memberGroups as $group) $groups[]= $group->get('id');
            }
            $_SESSION["modx.user.{$id}.userGroups"]= $groups;
        }
        return $groups;
    }

    /**
     * Gets all the User Group names of the groups this user belongs to.
     *
     * @access public
     * @return array An array of User Group names.
     */
    public function getUserGroupNames() {
        $groupNames= array();
        $id = $this->get('id') ? (string) $this->get('id') : '0';
        if (isset($_SESSION["modx.user.{$id}.userGroupNames"])) {
            $groupNames= $_SESSION["modx.user.{$id}.userGroupNames"];
        } else {
            $memberGroups= $this->xpdo->getCollectionGraph('modUserGroup', '{"UserGroupMembers":{}}', array('UserGroupMembers.member' => $this->get('id')));
            if ($memberGroups) {
                foreach ($memberGroups as $group) $groupNames[]= $group->get('name');
            }
            $_SESSION["modx.user.{$id}.userGroupNames"]= $groupNames;
        }
        return $groupNames;
    }

    /**
     * States whether a user is a member of a group or groups. You may specify
     * either a string name of the group, or an array of names.
     *
     * @access public
     * @param string|array $groups Either a string of a group name or an array
     * of names.
     * @param boolean $matchAll If true, requires the user to be a member of all
     * the groups specified. If false, the user can be a member of only one to
     * pass. Defaults to false.
     * @return boolean True if the user is a member of any of the groups
     * specified.
     */
    public function isMember($groups,$matchAll = false) {
        $isMember= false;
        $groupNames= $this->getUserGroupNames();
        if ($groupNames) {
            if (is_array($groups)) {
                if ($matchAll) {
                    $matches= array_diff($groups, $groupNames);
                    $isMember= empty($matches);
                } else {
                    $matches= array_intersect($groups, $groupNames);
                    $isMember= !empty($matches);
                }
            } else {
                $isMember= (array_search($groups, $groupNames) !== false);
            }
        }
        return $isMember;
    }

    /**
     * Join a User Group, and optionally assign a Role.
     *
     * @access public
     * @param mixed $groupId Either the name or ID of the User Group to join.
     * @param mixed $roleId Optional. Either the name or ID of the Role to
     * assign to for the group.
     * @return boolean True if successful.
     */
    public function joinGroup($groupId,$roleId = null) {
        $joined = false;

        $groupPk = is_string($groupId) ? array('name' => $groupId) : $groupId;
        /** @var modUserGroup $userGroup */
        $userGroup = $this->xpdo->getObject('modUserGroup',$groupPk);
        if (empty($userGroup)) {
            $this->xpdo->log(xPDO::LOG_LEVEL_ERROR,'User Group not found with key: '.$groupId);
            return $joined;
        }

        /** @var modUserGroupRole $role */
        if (!empty($roleId)) {
            $rolePk = is_string($roleId) ? array('name' => $roleId) : $roleId;
            $role = $this->xpdo->getObject('modUserGroupRole',$rolePk);
            if (empty($role)) {
                $this->xpdo->log(xPDO::LOG_LEVEL_ERROR,'Role not found with key: '.$role);
                return $joined;
            }
        }

        /** @var modUserGroupMember $member */
        $member = $this->xpdo->getObject('modUserGroupMember',array(
            'member' => $this->get('id'),
            'user_group' => $userGroup->get('id'),
        ));
        if (empty($member)) {
            $member = $this->xpdo->newObject('modUserGroupMember');
            $member->set('member',$this->get('id'));
            $member->set('user_group',$userGroup->get('id'));
            if (!empty($role)) {
                $member->set('role',$role->get('id'));
            }
            $joined = $member->save();
            if (!$joined) {
                $this->xpdo->log(xPDO::LOG_LEVEL_ERROR,'An unknown error occurred preventing adding the User to the User Group.');
            }
        } else {
            $joined = true;
        }
        return $joined;
    }

    /**
     * Removes the User from the specified User Group.
     *
     * @access public
     * @param mixed $groupId Either the name or ID of the User Group to join.
     * @return boolean True if successful.
     */
    public function leaveGroup($groupId) {
        $left = false;

        $c = $this->xpdo->newQuery('modUserGroupMember');
        $c->innerJoin('modUserGroup','UserGroup');
        $c->where(array('member' => $this->get('id')));

        $fk = is_string($groupId) ? 'name' : 'id';
        $c->where(array(
            'member' => $this->get('id'),
            'UserGroup.'.$fk => $groupId,
        ));

        /** @var modUserGroupMember $member */
        $member = $this->xpdo->getObject('modUserGroupMember',$c);
        if (empty($member)) {
            $this->xpdo->log(xPDO::LOG_LEVEL_ERROR,'User could not leave group with key "'.$groupId.'" because the User was not a part of that group.');
        } else {
            $left = $member->remove();
        }
        return $left;
    }

    /**
     * Remove any locks held by the user.
     *
     * @param array $options An array of options for controlling removal of specific locks or lock
     * types.
     * @return boolean True if the process was successful, or false if an error was encountered.
     */
    public function removeLocks(array $options = array()) {
        $removed = false;
        if ($this->xpdo instanceof modX) {
            if ($this->xpdo->getService('registry', 'registry.modRegistry')) {
                $this->xpdo->registry->addRegister('locks', 'registry.modDbRegister', array('directory' => 'locks'));
                $this->xpdo->registry->locks->connect();

                $this->xpdo->registry->locks->subscribe('/resource/');
                if ($msgs = $this->xpdo->registry->locks->read(array('remove_read' => false, 'poll_limit' => 1))) {
                    foreach ($msgs as $resource => $user) {
                        if ($user == $this->get('id')) {
                            $this->xpdo->registry->locks->subscribe('/resource/' . md5($resource));
                            $this->xpdo->registry->locks->read(array('remove_read' => true, 'poll_limit' => 1));
                        }
                    }
                }
                $removed = true;
            }
        }
        return $removed;
    }

    /**
     * Returns a randomly generated password
     *
     * @param integer $length The length of the password
     * @param array $options
     * @return string The newly generated password
     */
    public function generatePassword($length = 10,array $options = array()) {
        $options = array_merge(array(
            'allowable_characters' => 'abcdefghjkmnpqrstuvxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789',
            'srand_seed_multiplier' => 1000000,
        ),$options);

        $ps_len = strlen($options['allowable_characters']);
        srand((double) microtime() * $options['srand_seed_multiplier']);
        $pass = '';
        for ($i = 0; $i < $length; $i++) {
            $pass .= $options['allowable_characters'][mt_rand(0, $ps_len -1)];
        }
        return $pass;
    }

    /**
     * Send an email to the user
     *
     * @param string $message The body of the email
     * @param array $options An array of options
     * @return boolean True if successful
     */
    public function sendEmail($message,array $options = array()) {
        if (!($this->xpdo instanceof modX)) return false;
        $profile = $this->getOne('Profile');
        if (empty($profile)) return false;

        $this->xpdo->getService('mail', 'mail.modPHPMailer');
        if (!$this->xpdo->mail) return false;
        
        $this->xpdo->mail->set(modMail::MAIL_BODY, $message);
        $this->xpdo->mail->set(modMail::MAIL_FROM, $this->xpdo->getOption('from',$options,$this->xpdo->getOption('emailsender')));
        $this->xpdo->mail->set(modMail::MAIL_FROM_NAME, $this->xpdo->getOption('fromName',$options,$this->xpdo->getOption('site_name')));
        $this->xpdo->mail->set(modMail::MAIL_SENDER, $this->xpdo->getOption('sender',$options,$this->xpdo->getOption('emailsender')));
        $this->xpdo->mail->set(modMail::MAIL_SUBJECT, $this->xpdo->getOption('subject',$options,$this->xpdo->getOption('emailsubject')));
        $this->xpdo->mail->address('to',$profile->get('email'),$profile->get('fullname'));
        $this->xpdo->mail->address('reply-to',$this->xpdo->getOption('sender',$options,$this->xpdo->getOption('emailsender')));
        $this->xpdo->mail->setHTML($this->xpdo->getOption('html',$options,true));
        if ($this->xpdo->mail->send() == false) {
            return false;
        }
        $this->xpdo->mail->reset();
        return true;
    }
}