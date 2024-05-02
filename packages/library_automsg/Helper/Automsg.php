<?php
/**
 * @package    AutoMsg
 * Version			: 4.0.0
 * @license https://www.gnu.org/licenses/gpl-3.0.html GNU/GPL
 * @copyright (C) 2024 ConseilGouz. All Rights Reserved.
 * @author ConseilGouz
 * @license    GNU/GPLv3
 */
// No direct access.

namespace ConseilGouz\Automsg\Helper;

defined('_JEXEC') or die();

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Mail\MailTemplate;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use ConseilGouz\Component\Automsg\Administrator\Model\ConfigModel;

class Automsg
{
    // get automsg params
    public static function getParams()
    {
        $model = new ConfigModel();
        return $model->getItem(1);
    }
    public static function createLog()
    {
        Log::addLogger(
            array('text_file' => 'com_automsg.log.php'),
            Log::ALL,
            array('com_automsg')
        );
    }
    // send single article emails
    public static function sendEmails($article, $users, $tokens, $deny)
    {
        $autoparams = self::getParams();
        $app = Factory::getApplication();
        $lang = $app->getLanguage();
        $lang->load('com_automsg');
        $msgcreator = $autoparams->msgcreator;
        $libdateformat = "d/M/Y h:m";
        if ($autoparams->log) { // need to log msgs
            self::createLog();
        }
        $creatorId = $article->created_by;
        if (!in_array($creatorId, $users) && (!in_array($creatorId, $deny))) { // creator not in users array : add it
            $users[] = $creatorId;
        }
        $creator = $app->getIdentity($creatorId);
        $url = "<a href='".URI::root()."index.php?option=com_content&view=article&id=".$article->id."' target='_blank'>".Text::_('COM_AUTOMSG_CLICK')."</a>";
        $info_cat = self::getCategoryName($article->catid);
        $cat_params = json_decode($info_cat[0]->params);
        $cat_img = "";
        if (isset($cat_params->image) && ($cat_params->image != "")) {
            $img = HTMLHelper::cleanImageURL($cat_params->image);
            $cat_img = '<img src="'.URI::root().pathinfo($img->url)['dirname'].'/'.pathinfo($img->url)['basename'].'" />';
        }
        $images  = json_decode($article->images);
        $article->introimg = "";
        if (!empty($images->image_intro)) { // into img exists
            $img = HTMLHelper::cleanImageURL($images->image_intro);
            $article->introimg = '<img src="'.URI::root().pathinfo($img->url)['dirname'].'/'.pathinfo($img->url)['basename'].'" />';
        }
        $article_tags = self::getArticleTags($article->id);
        $itemtags = "";
        foreach ($article_tags as $tag) {
            $itemtags .= '<span class="iso_tag_'.$tag->alias.'">'.(($itemtags == "") ? $tag->tag : "<span class='iso_tagsep'><span>-</span></span>".$tag->tag).'</span>';
        }

        foreach ($users as $user_id) {
            $receiver = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($user_id);
            $unsubscribe = "";
            if ($tokens[$user_id]) {
                $unsubscribe = "<a href='".URI::root()."index.php?option=com_automsg&view=automsg&layout=edit&token=".$tokens[$user_id]."' target='_blank'>".Text::_('COM_AUTOMSG_UNSUBSCRIBE')."</a>";
            }
            $go = false;
            // Collect data for mail
            $data = [
                'creator'   => $creator->name,
                'id'        => $article->id,
                'title'     => $article->title,
                'cat'       => $info_cat[0]->title,
                'date'      => HTMLHelper::_('date', $article->created, $libdateformat),
                'intro'     => $article->introtext,
                'catimg'    => $cat_img,
                'url'       => $url,
                'introimg'  => $article->introimg,
                'subtitle'  => '', // not used
                'tags'      => $itemtags,
                'featured'  => $article->featured,
                'unsubscribe'   => $unsubscribe
            ];
            if (($user_id == $creatorId) && ($msgcreator == 1)) { // mail specifique au createur de l'article
                $mailer = new MailTemplate('plg_content_automsg.ownermail', $receiver->getParam('language', $app->get('language')));
            } else {
                $mailer = new MailTemplate('plg_content_automsg.usermail', $receiver->getParam('language', $app->get('language')));
            }
            $mailer->addTemplateData($data);
            $mailer->addRecipient($receiver->email, $receiver->name);

            try {
                $res = $mailer->send();
            } catch (\Exception $e) {
                if ($autoparams->log) { // need to log msgs
                    Log::add('Erreur ----> Article : '.$article->title.' non envoyé à '.$receiver->email.'/'.$e->getMessage(), Log::ERROR, 'com_automsg');
                } else {
                    $app->enqueueMessage($e->getMessage().'/'.$receiver->email, 'error');
                }
                continue;
            }
            if ($autoparams->log == 2) { // need to log all msgs
                Log::add('Article OK : '.$article->title.' envoyé à '.$receiver->email, Log::DEBUG, 'com_automsg');
            }
        }
    }
    private static function getCategoryName($id)
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true);
        $query->select('*')
            ->from('#__categories ')
            ->where('id = '.(int)$id)
        ;
        $db->setQuery($query);
        return $db->loadObjectList();
    }
    private static function getArticleTags($id)
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true);
        $query->select('tags.title as tag, tags.alias as alias, tags.note as note, tags.images as images, parent.title as parent_title, parent.alias as parent_alias')
            ->from('#__contentitem_tag_map as map ')
            ->innerJoin('#__content as c on c.id = map.content_item_id')
            ->innerJoin('#__tags as tags on tags.id = map.tag_id')
            ->innerJoin('#__tags as parent on parent.id = tags.parent_id')
            ->where('c.id = '.(int)$id.' AND map.type_alias like "com_content%"')
        ;
        $db->setQuery($query);
        return $db->loadObjectList();
    }
    public static function getAutomsgToken($users)
    {
        $tokens = array();
        foreach ($users as $user) {
            $token = self::checkautomsgtoken($user);
            if ($token) {// token found
                $tokens[$user] = $token;
            }
        }
        return $tokens;
    }
    /* check if automsg token exists.
    *  if it does not, create it
    */
    private static function checkautomsgtoken($userId)
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
                 ->select(
                     [
                            $db->quoteName('profile_value'),
                        ]
                 )
                ->from($db->quoteName('#__user_profiles'))
                ->where($db->quoteName('user_id') . ' = :userid')
                ->where($db->quoteName('profile_key') . ' LIKE '.$db->quote('profile_automsg.token'))
                ->bind(':userid', $userId, ParameterType::INTEGER);

        $db->setQuery($query);
        $result = $db->loadResult();
        if ($result) {
            return $result;
        } // automsg token already exists => exit
        // create a token
        $query = $db->getQuery(true)
                ->insert($db->quoteName('#__user_profiles'));
        $token = mb_strtoupper(strval(bin2hex(openssl_random_pseudo_bytes(16))));
        $order = 2;
        $query->values(
            implode(
                ',',
                $query->bindArray(
                    [
                        $userId,
                        'profile_automsg.token',
                        $token,
                        $order++,
                    ],
                    [
                        ParameterType::INTEGER,
                        ParameterType::STRING,
                        ParameterType::STRING,
                        ParameterType::INTEGER,
                    ]
                )
            )
        );
        $db->setQuery($query);
        $db->execute();
        return $token;
    }
    /*
     * Asynchronous process : store article id in automsg table
     */
    public static function async($article)
    {
        $autoparams = self::getParams();

        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $date = Factory::getDate();

        $query = $db->getQuery(true)
        ->insert($db->quoteName('#__automsg'));
        $query->values(
            implode(
                ',',
                $query->bindArray(
                    [
                        0, // key
                        0, // state
                        $article->id,
                        $date->toSql(), // date created
                        null, // date modified
                        null
                    ],
                    [
                        ParameterType::INTEGER,
                        ParameterType::INTEGER,
                        ParameterType::INTEGER,
                        ParameterType::STRING,
                        ParameterType::NULL,
                        ParameterType::NULL
                    ]
                )
            )
        );
        $db->setQuery($query);
        $db->execute();
        if ($autoparams->log == 2) { // need to log all msgs
            self::createLog();
            Log::add('Article Async : '.$article->title, Log::DEBUG, 'com_automsg');
        }
    }
    public static function oneLine($article, $users, $deny)
    {
        $lang = Factory::getApplication()->getLanguage();
        $lang->load('com_automsg');

        $libdateformat = "d/M/Y h:m";
        $creatorId = $article->created_by;
        $creator = Factory::getApplication()->getIdentity($creatorId);
        $url = "<a href='".URI::root()."index.php?option=com_content&view=article&id=".$article->id."' target='_blank'>".Text::_("COM_AUTOMSG_CLICK")."</a>";
        $info_cat = self::getCategoryName($article->catid);
        $cat_params = json_decode($info_cat[0]->params);
        $cat_img = "";
        if (isset($cat_params->image) && ($cat_params->image != "")) {
            $img = HTMLHelper::cleanImageURL($cat_params->image);
            $cat_img = '<img src="'.URI::root().pathinfo($img->url)['dirname'].'/'.pathinfo($img->url)['basename'].'" />';
        }
        $images  = json_decode($article->images);
        $article->introimg = "";
        if (!empty($images->image_intro)) { // into img exists
            $img = HTMLHelper::cleanImageURL($images->image_intro);
            $article->introimg = '<img src="'.URI::root().pathinfo($img->url)['dirname'].'/'.pathinfo($img->url)['basename'].'" />';
        }
        if (!in_array($creatorId, $users) && (!in_array($creatorId, $deny))) { // creator not in users array : add it
            $users[] = $creatorId;
        }
        $article_tags = self::getArticleTags($article->id);
        $itemtags = "";
        foreach ($article_tags as $tag) {
            $itemtags .= '<span class="iso_tag_'.$tag->alias.'">'.(($itemtags == "") ? $tag->tag : "<span class='iso_tagsep'><span>-</span></span>".$tag->tag).'</span>';
        }
        $data = [
                'creator'   => $creator->name,
                'id'        => $article->id,
                'title'     => $article->title,
                'cat'       => $info_cat[0]->title,
                'catimg'    => $cat_img,
                'date'      => HTMLHelper::_('date', $article->created, $libdateformat),
                'intro'     => $article->introtext,
                'introimg'  => $article->introimg,
                'url'       => $url,
                'subtitle'  => '', // not used
                'tags'      => $itemtags,
                'featured'  => $article->featured,
            ];
        return $data;
    }
}
