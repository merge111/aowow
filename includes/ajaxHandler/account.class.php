<?php

if (!defined('AOWOW_REVISION'))
    die('illegal access');

class AjaxAccount extends AjaxHandler
{
    protected $validParams = ['exclude', 'weightscales'];
    protected $_post       = array(
        'groups' => [FILTER_SANITIZE_NUMBER_INT, null],
        'save'   => [FILTER_SANITIZE_NUMBER_INT, null],
        'delete' => [FILTER_SANITIZE_NUMBER_INT, null],
        'id'     => [FILTER_CALLBACK,            ['options' => 'AjaxHandler::checkIdList']],
        'name'   => [FILTER_CALLBACK,            ['options' => 'AjaxAccount::checkName']],
        'scale'  => [FILTER_CALLBACK,            ['options' => 'AjaxAccount::checkScale']],
        'reset'  => [FILTER_SANITIZE_NUMBER_INT, null],
        'mode'   => [FILTER_SANITIZE_NUMBER_INT, null],
        'type'   => [FILTER_SANITIZE_NUMBER_INT, null],
    );
    protected $_get        = array(
        'locale' => [FILTER_CALLBACK, ['options' => 'AjaxHandler::checkLocale']]
    );

    public function __construct(array $params)
    {
        parent::__construct($params);

        if (is_numeric($this->_get['locale']))
            User::useLocale($this->_get['locale']);

        if (!$this->params || !User::$id)
            return;

        // select handler
        if ($this->params[0] == 'exclude')
            $this->handler = 'handleExclude';
        else if ($this->params[0] == 'weightscales')
            $this->handler = 'handleWeightscales';
    }

    protected function handleExclude()
    {
        if (!User::$id)
            return;

        if ($this->_post['mode'] == 1)                      // directly set exludes
        {
            $type = $this->_post['type'];
            $ids  = $this->_post['id'];

            if (!isset(Util::$typeStrings[$type]) || empty($ids))
                return;

            // ready for some bullshit? here it comes!
            // we don't get signaled whether an id should be added to or removed from either includes or excludes
            // so we throw everything into one table and toggle the mode if its already in here

            $includes = DB::Aowow()->selectCol('SELECT typeId FROM ?_profiler_excludes WHERE type = ?d AND typeId IN (?a)', $type, $ids);

            foreach ($ids as $typeId)
                DB::Aowow()->query('INSERT INTO ?_account_excludes (`userId`, `type`, `typeId`, `mode`) VALUES (?a) ON DUPLICATE KEY UPDATE mode = (mode ^ 0x3)', array(
                    User::$id, $type, $typeId, in_array($includes, $typeId) ? 2 : 1
                ));

            return;
        }
        else if ($this->_post['reset'] == 1)                // defaults to unavailable
        {
            $mask = PR_EXCLUDE_GROUP_UNAVAILABLE;
            DB::Aowow()->query('DELETE FROM ?_account_excludes WHERE userId = ?d', User::$id);
        }
        else                                                // clamp to real groups
            $mask = $this->_post['groups'] & PR_EXCLUDE_GROUP_ANY;

        DB::Aowow()->query('UPDATE ?_account SET excludeGroups = ?d WHERE id = ?d', $mask, User::$id);

        return;
    }

    protected function handleWeightscales()
    {
        if ($this->_post['save'])
        {
            if (!$this->_post['scale'])
                return 0;

            $id = 0;

            if ($this->_post['id'] && ($id = $this->_post['id'][0]))
            {
                if (!DB::Aowow()->selectCell('SELECT 1 FROM ?_account_weightscales WHERE userId = ?d AND id = ?d', User::$id, $id))
                    return 0;

                DB::Aowow()->query('UPDATE ?_account_weightscales SET `name` = ? WHERE id = ?d', $this->_post['name'], $id);
            }
            else
            {
                $nScales = DB::Aowow()->selectCell('SELECT COUNT(id) FROM ?_account_weightscales WHERE userId = ?d', User::$id);
                if ($nScales >= 5)                          // more or less hard-defined in LANG.message_weightscalesaveerror
                    return 0;

                $id = DB::Aowow()->query('INSERT INTO ?_account_weightscales (`userId`, `name`) VALUES (?d, ?)', User::$id, $this->_post['name']);
            }

            DB::Aowow()->query('DELETE FROM ?_account_weightscale_data WHERE id = ?d', $id);

            foreach (explode(',', $this->_post['scale']) as $s)
            {
                list($k, $v) = explode(':', $s);
                if (!in_array($k, Util::$weightScales) || $v < 1)
                    continue;

                DB::Aowow()->query('INSERT INTO ?_account_weightscale_data VALUES (?d, ?, ?d)', $id, $k, $v);
            }

            return $id;
        }
        else if ($this->_post['delete'] && $this->_post['id'] && $this->_post['id'][0])
            DB::Aowow()->query('DELETE FROM ?_account_weightscales WHERE id = ?d AND userId = ?d', $this->_post['id'][0], User::$id);
        else
            return 0;
    }

    protected function checkScale($val)
    {
        if (preg_match('/^((\w+:\d+)(,\w+:\d+)*)$/', $val))
            return $val;

        return null;
    }

    protected function checkName($val)
    {
        $var = trim(urldecode($val));

        return filter_var($var, FILTER_SANITIZE_STRING, FILTER_FLAG_STRIP_LOW);
    }
}
