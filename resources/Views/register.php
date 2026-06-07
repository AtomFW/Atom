<?php
/** @var $model \Atom\Model */

use Atom\form\Form;

$form = new Form();
?>

<h1>Register</h1>

<?php $form = Form::begin('', 'post', ["class" => "form-post"]) ?>
    <div class="row">
        <div class="col">
            <?php echo $form->field($model, 'username') ?>
        </div>
        <div class="col">
            <?php echo $form->field($model, 'firstName') ?>
        </div>
        <div class="col">
            <?php echo $form->field($model, 'lastName') ?>
        </div>
    </div>
    <?php // echo $form->field($model, 'email', )->emailField() ?>
    <?php // echo $form->field($model, 'password')->passwordField() ?>
    <?php // echo $form->field($model, 'passwordConfirm')->passwordField() ?>
    <?php // echo $form->textareaField($model, 'textarea') ?>
    <?php // echo $form->selectField($model, 'optionone') ?>
    <?php // echo $form->progressField($model, 'pro') ?>
    <?php // echo $form->meterField($model, 'met') ?>
    <button class="btn btn-success">Submit</button>
<?php Form::end() ?>