<?php namespace EqemuLogin\Pub\Controller;

use EqemuLogin\LoginServerDb;
use XF;
use XF\App;
use XF\Http\Request;
use XF\Pub\Controller\AbstractController;
use XF\Mvc\ParameterBag;

class LoginServerController extends AbstractController
{
    protected $user;
    protected $loginDb;

    public function __construct(App $app, Request $request)
    {
        parent::__construct($app, $request);
        $this->user = $user = XF::visitor();
        $this->loginDb = LoginServerDb::getInstance()->getConnection();
    }

    /**
     * @param ParameterBag $params
     * @return XF\Mvc\Reply\View
     * @throws XF\Mvc\Reply\Exception
     */
    public function actionIndex(ParameterBag $params)
    {
        // Must be logged in to view this page
        $this->assertRegistrationRequired();
        $loginServerAccounts = $this->getLoginServerAccounts();

        foreach ($loginServerAccounts as $key => $account) {
            $loginServerAccounts[$key]['characters'] = $this->getCharactersByLoginAccount($account['id']);
        }

        $accountsData = [
            "login_server_accounts" => $loginServerAccounts
        ];

        return $this->view('EqemuLogin:LoginAccountIndex', 'login_account_index', $accountsData);
    }

    /**
     * @return XF\Mvc\Reply\View
     * @throws XF\Mvc\Reply\Exception
     */
    public function actionCreateAccountForm() {
        $this->assertRegistrationRequired();
        return $this->view('EqemuLogin:CreateAccountForm', 'create_account_form', []);
    }

    /**
     * @return XF\Mvc\Reply\Error|XF\Mvc\Reply\Redirect
     * @throws XF\Mvc\Reply\Exception
     */
    public function actionCreateAccount()
    {
        $this->setResponseType('json');
        $this->assertPostOnly();
        $this->assertRegistrationRequired();
        $accountName = $this->filter('username', 'string');
        $password = $this->filter('password', 'string');
        $confirmPassword = $this->filter('confirm_password', 'string');

        // This is where you would add further validation, regex for valid values, etc.
        if ($this->loginServerAccountExists($accountName) || empty($accountName) || empty($password)) {
            return $this->error("Username Not Accepted", 400);
        }

        if($confirmPassword !== $password) {
            return $this->error(\XF::phrase('passwords_did_not_match'));
        }

        $accountData = [
            "account_name" => $accountName,
            "forum_name" => $this->user->get("username"),
            "account_email" => $this->user->get("email"),
            "account_password" => hash("SHA1", $password),
            "created_at" => date("Y-m-d H:i:s"),
            "source_loginserver" => "local",
        ];

        try {
            $this->loginDb->insert("login_accounts", $accountData);
        } catch (\Exception $exception) {
            throw $this->exception($this->error(
                "Something went wrong 3", 500
            ));
        }

        $accountTemplate = XF::app()->templater()->renderTemplate('public:login_account', ['account' => $accountData]);
        $redirect = $this->redirect($this->buildLink("login-server"),
            "Account {$accountData['account_name']} added successfully.");
        $redirect->setJsonParam("template", $accountTemplate);
        return $redirect;
    }

    /**
     * @param ParameterBag $params
     * @return XF\Mvc\Reply\View
     * @throws XF\Mvc\Reply\Exception
     */
    public function actionUpdatePasswordForm(ParameterBag $params) {
        $this->assertRegistrationRequired();
        return $this->view('EqemuLogin:UpdatePasswordForm',
            'password_form', ['account_name' => $this->filter("account_name", "string")]);
    }

    /**
     * @return XF\Mvc\Reply\Error|XF\Mvc\Reply\Message
     * @throws XF\Mvc\Reply\Exception
     */
    public function actionUpdatePassword()
    {
        $this->setResponseType('json');
        $this->assertPostOnly();
        $this->assertRegistrationRequired();
        $loginServerAccount = $this->filter('login_server_account', 'string');
        $newPassword = $this->filter('password', 'string');
        $confirmPassword = $this->filter('confirm_password', 'string');
        $this->validateAccountOwnership($loginServerAccount);

        if($confirmPassword !== $newPassword) {
            return $this->error(\XF::phrase('passwords_did_not_match'));
        }

        try {
            $this->loginDb->update(
                'login_accounts',
                ["account_password" => hash("SHA1", $newPassword)],
                'account_name = ? AND forum_name = ?',
                [$loginServerAccount, $this->user->get('username')]
            );
        } catch (\Exception $exception) {
            throw $this->exception($this->error(
                "Something went wrong", 500
            ));
        }

        return $this->message("Password Updated Successfully For $loginServerAccount");
    }

    /**
     * @param $loginServerAccount
     * @return bool
     * @throws XF\Mvc\Reply\Exception
     */

    protected function validateAccountOwnership($loginServerAccount)
    {
        $existingAccount = $this->loginDb->fetchAll(
            "SELECT * FROM login_accounts WHERE forum_name = ? AND account_name = ?",
            [$this->user->get("username"), $loginServerAccount]
        );

        if (empty($existingAccount)) {
            throw $this->exception($this->error(
                "Not Authorized", 401
            ));
        }

        return true;
   }



    protected function getLoginServerAccounts()
    {
        return $this->loginDb->fetchAll(
            "SELECT * FROM login_accounts WHERE forum_name = ?",
            [$this->user->get("username")]
        );
    }

    protected function getCharactersByLoginAccount($loginServerId)
    {
        return $this->loginDb->fetchAll(
            "SELECT DISTINCT
			character_data.name,
			character_data.level,
			class_skill.name AS class_name,
			zone.long_name AS zone_name
		FROM
			character_data,
			class_skill,
			zone,
			account
		WHERE
			character_data.account_id = account.id AND
			character_data.class = class_skill.class AND
			character_data.zone_id = zone.zoneidnumber AND
			character_data.deleted_at IS NULL AND
			zone.version = 0 AND
			account.lsaccount_id = ?",
            [$loginServerId]
        );
    }

    private function loginServerAccountExists($accountName)
    {
        $accounts = $this->loginDb->fetchAll(
            "SELECT * FROM login_accounts WHERE account_name = ?",
            [$accountName]
        );

        return count($accounts) > 0;
    }

    /**
     * @param ParameterBag $params
     * @return XF\Mvc\Reply\View
     * @throws XF\Mvc\Reply\Exception
     */
    public function actionMigrateForm(ParameterBag $params)
    {
        $this->assertRegistrationRequired();

        if (!\XF::options()->eqemuLogin_enable_migrate) {
            return $this->error("Character migration is currently disabled.");
        }

        $characterName = $this->filter('character_name', 'string');

        // Fetch character information with the account details
        $character = $this->loginDb->fetchRow("
            SELECT cd.*, cd.id AS char_id, cd.name AS char_name, a.lsaccount_id, a.name AS account_name
            FROM character_data cd
            JOIN account a ON cd.account_id = a.id
            WHERE cd.name = ? AND cd.deleted_at IS NULL
        ", [$characterName]);

        if (empty($character)) {
            return $this->error("Character not found.");
        }

        // Validate that user owns the login account for this character
        $ownedLoginAccounts = $this->getLoginServerAccounts();
        $ownedLoginAccountIds = array_column($ownedLoginAccounts, 'id');

        if (empty($ownedLoginAccountIds) || !in_array($character['lsaccount_id'], $ownedLoginAccountIds)) {
            return $this->error("Not Authorized", 401);
        }

        // Fetch all other game accounts owned by this forum user (excluding the character's current account)
        $ownedGameAccounts = $this->loginDb->fetchAll("
            SELECT id, name
            FROM account
            WHERE lsaccount_id IN (" . implode(',', array_map('intval', $ownedLoginAccountIds)) . ")
        ");

        $targetAccounts = [];
        foreach ($ownedGameAccounts as $acct) {
            if ($acct['id'] != $character['account_id']) {
                $targetAccounts[] = $acct;
            }
        }

        $viewParams = [
            'character' => $character,
            'targetAccounts' => $targetAccounts
        ];

        return $this->view('EqemuLogin:MigrateCharacterForm', 'migrate_character_form', $viewParams);
    }

    /**
     * @return XF\Mvc\Reply\Error|XF\Mvc\Reply\Redirect
     * @throws XF\Mvc\Reply\Exception
     */
    public function actionMigrate()
    {
        $this->assertPostOnly();
        $this->assertRegistrationRequired();

        if (!\XF::options()->eqemuLogin_enable_migrate) {
            return $this->error("Character migration is currently disabled.");
        }

        $characterName = $this->filter('character_name', 'string');
        $targetAccountId = $this->filter('target_account_id', 'int');

        // Fetch character information
        $character = $this->loginDb->fetchRow("
            SELECT cd.*, cd.id AS char_id, cd.name AS char_name, a.lsaccount_id, a.name AS account_name
            FROM character_data cd
            JOIN account a ON cd.account_id = a.id
            WHERE cd.name = ? AND cd.deleted_at IS NULL
        ", [$characterName]);

        if (empty($character)) {
            return $this->error("Character not found.");
        }

        if ($character['account_id'] == $targetAccountId) {
            return $this->error("Character is already on this account.");
        }

        // Fetch owned login accounts
        $ownedLoginAccounts = $this->getLoginServerAccounts();
        $ownedLoginAccountIds = array_column($ownedLoginAccounts, 'id');

        // Fetch target game account
        $targetAccount = $this->loginDb->fetchRow("
            SELECT id, name, lsaccount_id FROM account WHERE id = ?
        ", [$targetAccountId]);

        if (empty($targetAccount)) {
            return $this->error("Target account not found.");
        }

        // Validation: Must own both source login account and target login account
        if (empty($ownedLoginAccountIds) 
            || !in_array($character['lsaccount_id'], $ownedLoginAccountIds)
            || !in_array($targetAccount['lsaccount_id'], $ownedLoginAccountIds)
        ) {
            return $this->error("Not Authorized", 401);
        }

        // Validation: Online check (using 'ingame' column from character_data schema)
        if (isset($character['ingame']) && $character['ingame'] > 0) {
            return $this->error("This character is currently online in-game. Please log off first.");
        }

        // Validation: Limit target account to standard maximum 8 characters
        $targetCharCount = $this->loginDb->fetchOne("
            SELECT COUNT(*) FROM character_data WHERE account_id = ? AND deleted_at IS NULL
        ", [$targetAccountId]);

        if ($targetCharCount >= 8) {
            return $this->error("The target account already has the maximum of 8 characters.");
        }

        // Validation: Migration Cooldown (XenForo-side tracking)
        $cooldownDays = \XF::options()->eqemuLogin_migrate_cooldown;
        if ($cooldownDays > 0) {
            $lastMigrationDate = \XF::db()->fetchOne("
                SELECT MAX(migration_date)
                FROM xf_eqemu_migration_log
                WHERE user_id = ?
            ", [$this->user->user_id]);

            if ($lastMigrationDate) {
                $cooldownSeconds = $cooldownDays * 24 * 60 * 60;
                $elapsedSeconds = time() - $lastMigrationDate;

                if ($elapsedSeconds < $cooldownSeconds) {
                    $remainingSeconds = $cooldownSeconds - $elapsedSeconds;
                    $remainingDays = ceil($remainingSeconds / (24 * 60 * 60));
                    return $this->error("You can only migrate a character once every {$cooldownDays} days. You must wait {$remainingDays} more day(s).");
                }
            }
        }

        // Validation: Offline safety check
        $offlineMinutes = \XF::options()->eqemuLogin_migrate_offline_duration;
        if ($offlineMinutes > 0) {
            $offlineSeconds = $offlineMinutes * 60;

            // Check character activity using character_data.last_login (which is a Unix timestamp in this schema)
            if (isset($character['last_login']) && $character['last_login'] > 0) {
                $elapsed = time() - intval($character['last_login']);
                if ($elapsed < $offlineSeconds) {
                    $remaining = ceil(($offlineSeconds - $elapsed) / 60);
                    return $this->error("The character was active in-game recently. Please wait {$remaining} minutes before transferring.");
                }
            }
        }

        // Perform migration: Update character's account association
        try {
            $this->loginDb->update(
                'character_data',
                ['account_id' => $targetAccountId],
                'id = ?',
                [$character['char_id']]
            );

            // Log the migration in the forum DB
            \XF::db()->insert('xf_eqemu_migration_log', [
                'user_id' => $this->user->user_id,
                'character_name' => $character['char_name'],
                'source_account_id' => $character['account_id'],
                'target_account_id' => $targetAccountId,
                'migration_date' => time()
            ]);
        } catch (\Exception $exception) {
            return $this->error("Something went wrong during the character migration. Please try again later.", 500);
        }

        return $this->redirect(
            $this->buildLink("login-server"),
            "Character '{$character['char_name']}' has been successfully migrated to account '{$targetAccount['name']}'."
        );
    }
}
