<?php

/**
 * @file
 * Contains \Drupal\quiz\Controller\QuizController.
 */

namespace Drupal\quiz\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityManager;
use Drupal\Core\Form\BaseFormIdInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Render\Element\Form;
use Drupal\Core\Routing\LinkGeneratorTrait;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\quiz\Entity\Form\AnswerForm;
use Drupal\quiz\Entity\Form\QuizForm;
use Drupal\quiz\Entity\QuizHasQuestion;
use Drupal\quiz\Entity\UserQuizStatus;
use Drupal\quiz\Form\QuizSelectedQuestionsForm;
use Drupal\quiz\Form\QuizUnselectedQuestionsForm;
use Drupal\quiz\QuestionInterface;
use Drupal\quiz\QuestionListBuilder;
use Drupal\quiz\QuestionTypeInterface;
use Drupal\quiz\QuestionTypeListBuilder;
use Drupal\quiz\QuizInterface;
use Drupal\quiz\QuizTypeInterface;
use Drupal\Core\Link;
use Drupal\quiz\UserQuizStatusInterface;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;

/**
 * Class QuizController.
 *
 * @package Drupal\quiz\Controller
 */
class QuizController extends ControllerBase {
  use LinkGeneratorTrait;

  use ContainerAwareTrait;
  /**
   * Adds an answer to a question.
   *
   * @param \Drupal\quiz\UserQuizStatusInterface $state
   * @return array
   *  Returns form for adding an answer.
   */
  public function addAnswer(UserQuizStatusInterface $state) {
    $answer = static::entityTypeManager()->getStorage('answer')->create(array(
      'type' => $state->getCurrentQuestion()->get('answer_type')->target_id,
      'question' => $state->getCurrentQuestionId(),
      'user_quiz_status' => $state->id(),
    ));


    $form = $this->entityFormBuilder()->getForm($answer);

    return $form;
  }

  /**
   * Builds a title for a question in format question x of n.
   *
   * @param \Drupal\quiz\UserQuizStatusInterface $state
   * @return string
   *  Returns title string.
   */
  public function addAnswerTitle(UserQuizStatusInterface $state) {

    /* @var $quiz \Drupal\quiz\Entity\Quiz*/
    $quiz = $state->getQuiz();

    return $this->t('Question %x of %n',array(
      '%x' => ($state->getAnswerCount() + 1),
      '%n' => count($quiz->getQuestions())
    ));

  }

  /**
   * Adds a new question.
   *
   * @param $quiz
   *    The quiz for which to add the question.
   * @param \Drupal\quiz\QuestionTypeInterface $question_type
   *    The type of question.
   * @return array
   *    New question type form.
   */
  public function addQuestion($quiz, QuestionTypeInterface $question_type) {
    $answer_type = NULL;
    if($question_type->isTrueFalse())
      $answer_type = 'true_or_false';
    if($question_type->isText())
      $answer_type = 'text_answer';
    if($question_type->isMultipleChoice())
      $answer_type = 'multiple_choice_answer';
    $question = static::entityTypeManager()->getStorage('question')->create(array(
      'type' => $question_type->id(),
      'answer_type' => $answer_type,
      'quiz' => $quiz,
    ));

    $form = $this->entityFormBuilder()->getForm($question);

    return $form;
  }

  use StringTranslationTrait;
  /**
   * Adds a new quiz. If no quiz type is provided,
   * the type is set automatically to basic_quiz.
   *
   * @param \Drupal\quiz\QuizTypeInterface|NULL $quiz_type
   * @return array
   *  Returns a new quiz form.
   */
  public function addQuiz(QuizTypeInterface $quiz_type = NULL) {
    if ($quiz_type == NULL) {
      $quiz = static::entityTypeManager()->getStorage('quiz')->create(array(
        'type' => 'basic_quiz',
      ));
    }
    else {
      $quiz = static::entityTypeManager()->getStorage('quiz')->create(array(
        'type' => $quiz_type->id(),
      ));
    }
    $form = $this->entityFormBuilder()->getForm($quiz);
    return $form;
  }

  /**
   * Uses QuestionFormBuilder to build a list of questions for a given quiz
   *
   * @param \Drupal\quiz\QuizInterface $quiz
   * @return array
   *    Returns a rendable array.
   */
  public function listQuestions(QuizInterface $quiz) {
    $form_object = new QuizSelectedQuestionsForm($quiz);
    $form_builder = \Drupal::formBuilder();

    $form_second = new QuizUnselectedQuestionsForm($quiz, $this->entityTypeManager());
    $form['questions'] = $form_builder->getForm($form_object);
    $form['unselected'] = $form_builder->getForm($form_second);

    return ($form);
  }

  /**
   * Creates a new QuizHasQuestion instance.
   *
   * @param \Drupal\quiz\QuizInterface $quiz
   * @param \Drupal\quiz\QuestionInterface $question
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   */
  public function bindQuestion(QuizInterface $quiz, QuestionInterface $question) {
    $entity = QuizHasQuestion::create(array())
      ->setQuestion($question)
      ->setQuiz($quiz)
      ->save();
    return $this->redirect('entity.quiz.canonical_admin', [
      'quiz' => $quiz->id(),
    ]);
  }

  /**
   * Deletes all QuizHasQuestion instances for a Quiz and a Question.
   *
   * @param \Drupal\quiz\QuizInterface $quiz
   * @param \Drupal\quiz\QuestionInterface $question
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   */
  public function unbindQuestion(QuizInterface $quiz, QuestionInterface $question) {
    $quiz->removeQuestion($question);
    return $this->redirect('entity.quiz.canonical_admin', [
      'quiz' => $quiz->id(),
    ]);
  }


  /**
   * Lists the question types for a new question in a quiz.
   *
   * @param \Drupal\quiz\QuizInterface $quiz
   * @return array
   *  Rendable array
   */
  public function pickQuestionType(QuizInterface $quiz) {
    $storage = static::entityTypeManager()->getStorage('question_type');
    $builder = new QuestionTypeListBuilder($storage->getEntityType(), $storage);
    $builder->setQuizId($quiz->id());
    $builder->load();
    $result = $builder->render();
    return $result;
  }

  /**
   * Controls the quiz.
   *
   * @param \Drupal\quiz\QuizInterface $quiz
   * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
   *  Returns a redirect for the first question if questions exist
   *  else it returns a redirect back to quiz.
   */
  public function takeQuiz(QuizInterface $quiz) {
    // Only attempt quiz if it has questions.
    $attemptLimit = $quiz->getAttemptLimit();

    if($attemptLimit > 0) {
      $statuses = $quiz->getStatuses($this->currentUser());
      if (count($statuses) >= $attemptLimit) {
        $status = $quiz->getActiveStatus($this->currentUser());
        if($status == NULL) {
          drupal_set_message($this->t('Maximum attempts for this quiz reached.'), 'warning');
          return $this->redirect('entity.quiz.canonical', array('quiz' => $quiz->id()));
        }
      }
    }

    $questions = $quiz->getQuestions();
    if (count($questions) != 0) {

      $status = $quiz->getActiveStatus($this->currentUser());

      // If no open quiz session is found, create one.
      if($status == NULL) {
        $status = UserQuizStatus::create(array());
        $status->setQuiz($quiz);
        $status->setFinished(0);
        $status->setAnswerCount(0);
        $status->setQuestionsCount($quiz->getQuestionCount());
        $status->save();
      }

      $next = 0;
      $nextQuestion = NULL;
      // Take questions in order mechanism. Extend here to implement random order.
      foreach ($questions as $question) {
        /* @var $question \Drupal\quiz\Entity\Question */
        if ($status->getLastQuestionId() == NULL || $next) {
          $nextQuestion = $question;
          break;
        }
        if ($status->getLastQuestionId() == $question->id()) {
          $next = 1;
        }

      }

      // There is a question to be answered case.
      if ($nextQuestion != NULL) {
        $status->setCurrentQuestion($nextQuestion);
        $status->save();
        return $this->redirect('entity.answer.add_answer', array('state' => $status->id()));
      }
      // Quiz completed case.
      elseif($status->isFinished() == 0) {
          $status->setScore($status->evaluate());
          $status->setMaxScore($quiz->getMaxScore());
          $status->setPercent($quiz->getPercentile());

          $status->setQuestionsCount($quiz->getQuestionCount());
          $status->setFinished(time());
          $status->setCurrentQuestion();
          $status->save();
        }
        return $this->redirect('entity.quiz.canonical', array('quiz' => $quiz->id()));
      }

    drupal_set_message($this->t('This quiz has no questions.'), 'warning');
    return $this->redirect('entity.quiz.canonical', array('quiz' => $quiz->id()));
  }

  /**
   * Sets the quiz title as its name.
   *
   * @param \Drupal\quiz\QuizInterface $quiz
   * @return string
   */
  public function userDisplayQuizTitle(QuizInterface $quiz) {
    return $quiz->getName();
  }


  /**
   * Gets all the answers.
   *
   * @param \Drupal\quiz\QuizInterface $quiz
   * @param \Drupal\Core\Session\AccountInterface $user
   * @return array
   *
   * @deprecated Use the functionality built into UserQuizStatus.
   */
  public function getAllAnswers(QuizInterface $quiz, AccountInterface $user) {
    $answerStorage = static::entityTypeManager()->getStorage('answer');
    $query = $answerStorage->getQuery();
    $aids = $query
      ->Condition('user_id', $user->id())
      ->execute();
    $answers = $answerStorage->loadMultiple($aids);
    $answerArray = array();
    foreach ($answers as $answer) {
      /* @var $answer \Drupal\quiz\Entity\Answer */
      if ($answer->getQuestion()->getQuiz()->id() == $quiz->id()) {
        $answerArray[] = $answer;
      }
    }
    return $answerArray;
  }

  /**
   * Deletes all answers to a quiz for a user. If no user is specified
   * the current user is assumed.
   *
   * @param \Drupal\quiz\QuizInterface $quiz
   * @param \Drupal\Core\Session\AccountInterface|NULL $user
   * @return array
   *  Returns a redirect to the canonical quiz page.
   *
   * @TODO: Also delete states, not just answer entities.
   * @deprecated Just don't use it, it makes no sense.
   */
  public function resetQuiz(QuizInterface $quiz, AccountInterface $user = NULL) {
    if($user == NULL) {
      $user = $this->currentUser();
    }
    $answers = $this->getAllAnswers($quiz, $user);
    $counter = 0;
    foreach ($answers as $answer) {
      /* @var $answer \Drupal\quiz\Entity\Answer */
      $answer->delete();
      $counter++;
    }
    drupal_set_message($this->t('Deleted %count answers.', array('%count' => $counter)));
    return $this->redirect('entity.quiz.canonical', array('quiz' => $quiz->id()));
  }
}