<?php
?>
<div>
    <?php
    echo $form->field($model, "{$rel_attribute}{$ext}")->widget(\kartik\file\FileInput::classname(), $options)->label(false);
    // relation tag 'AVATAR', 'LOGO'...
    //echo $form->field($model, "rel_type_tag{$ext}")->hiddenInput(['value' => $rel_type_tag])->label(false);
    // abandonned, get the re type tag from the behavior based on the model
    ?>
</div>