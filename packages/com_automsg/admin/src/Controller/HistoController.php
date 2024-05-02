<?php
/**
 * @component     AutoMsg - Joomla 4.x/5.x
 * Version			: 4.0.0
 * @license https://www.gnu.org/licenses/gpl-3.0.html GNU/GPL
 * @copyright (c) 2024 ConseilGouz. All Rights Reserved.
 * @author ConseilGouz 
**/

namespace ConseilGouz\Component\Automsg\Administrator\Controller;

\defined('_JEXEC') or die;
use Joomla\CMS\MVC\Controller\FormController;
use Joomla\CMS\Factory;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

class HistoController extends FormController
{
    protected function allowAdd($data = array())
    {
        // Initialise variables.
        $user		= $this->app->getIdentity();
        $allow		= null;
        if ($allow === null) {
            // In the absense of better information, revert to the component permissions.
            return parent::allowAdd($data);
        } else {
            return $allow;
        }
    }

    protected function allowEdit($data = array(), $key = 'id')
    {
        $recordId = (int) isset($data[$key]) ? $data[$key] : 0;
        $user = $this->app->getIdentity();

        // Zero record (id:0), return component edit permission by calling parent controller method
        if (!$recordId) {
            return parent::allowEdit($data, $key);
        }

        // Check edit on the record asset (explicit or inherited)
        if ($user->authorise('core.edit', 'com_automsg.waiting.' . $recordId)) {
            return true;
        }

        // Check edit own on the record asset (explicit or inherited)
        if ($user->authorise('core.edit.own', 'com_automsg.waiting.' . $recordId)) {
            // Existing record already has an owner, get it
            $record = $this->getModel()->getItem($recordId);

            if (empty($record)) {
                return false;
            }
            return true;
        }

        return false;

    }

    public function cancel($key = null)
    {
        // $result = parent::cancel();
        $app = Factory::getApplication();
        $return = Uri::base().'index.php?option=com_automsg&view=histo';
        $app->redirect($return);
        return true;
    }

    public function save($key = null, $urlVar = null)
    {
        // Check for request forgeries.
        Session::checkToken() or jexit(Text::_('JINVALID_TOKEN'));

        // Initialise variables.
        $app = Factory::getApplication();
        $model = $this->getModel('histo');
        $data = $app->input->getVar('jform', array(), 'post', 'array');
        $task = $this->getTask();
        $context = 'com_automsg.edit.histo';
        $recordId = $app->input->getInt('id');
        // Populate the row id from the session.
        $data['id'] = $recordId;
        // Check for validation errors.
        if ($data === false) {
            // Get the validation messages.
            $errors = $model->getErrors();

            // Push up to three validation messages out to the user.
            for ($i = 0, $n = count($errors); $i < $n && $i < 3; $i++) {
                if ($errors[$i] instanceof \Exception) {
                    $app->enqueueMessage($errors[$i]->getMessage(), 'warning');
                } else {
                    $app->enqueueMessage($errors[$i], 'warning');
                }
            }
            // Save the data in the session.
            $app->setUserState('com_automsg.edit.histo.data', $data);
            // Redirect back to the edit screen.
            $this->setRedirect(Route::_('index.php?option=com_automsg&view=' . $this->view_item . $this->getRedirectToItemAppend($recordId), false));
            return false;
        }
        $data['modified'] = Factory::getDate()->toSql();
        // Attempt to save the data.
        if (!$model->save($data)) {
            // Save the data in the session.
            $app->setUserState('com_automsg.edit.histo.data', $data);
            // Redirect back to the edit screen.
            $this->setMessage(Text::sprintf('JLIB_APPLICATION_ERROR_SAVE_FAILED', $model->getError()), 'warning');
            $this->setRedirect(Route::_('index.php?option=com_automsg&view=' . $this->view_item . $this->getRedirectToItemAppend($recordId), false));
            return false;
        }

        $this->setMessage(Text::_('Save sucess!'));
        // Redirect the user and adjust session state based on the chosen task.
        switch ($task) {
            case 'apply':
                // Set the row data in the session.
                $recordId = $model->getState($this->context . '.id');
                $this->holdEditId($context, $recordId);
                $app->setUserState('com_automsg.edit.histo.data', null);

                // Redirect back to the edit screen.
                $this->setRedirect(Route::_('index.php?option=com_automsg&view=' . $this->view_item . $this->getRedirectToItemAppend($recordId), false));
                break;
            case 'save2new':
                // Clear the row id and data in the session.
                $this->releaseEditId($context, $recordId);
                $app->setUserState('com_automsg.edit.histo.data', null);
                // Redirect back to the edit screen.
                $this->setRedirect(Route::_('index.php?option=com_automsg&view=' . $this->view_item . $this->getRedirectToItemAppend(), false));
                break;
            default:
                // Clear the row id and data in the session.
                $this->releaseEditId($context, $recordId);
                $app->setUserState('com_automsg.edit.histo.data', null);
                // Redirect to the list screen.
                $this->setRedirect(Route::_('index.php?option=com_automsg&view=' . $this->view_list . $this->getRedirectToListAppend(), false));
                break;
        }
    }
}
