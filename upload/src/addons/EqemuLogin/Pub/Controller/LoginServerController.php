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
            "SELECT UNIQUE
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
}
