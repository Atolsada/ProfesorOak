<?php

if($this->pokemon->step($telegram->user->id) == 'SCREENSHOT_VERIFY'){
	if(!$telegram->is_chat_group() && $telegram->photo()){
		$userid = $this->telegram->user->id;
		$pokeuser = $pokemon->user($userid);
		if(empty($pokeuser->username) or $pokeuser->lvl == 1){
			$text = "Antes de validarte, necesito saber tu *";
			$add = array();
			if(empty($pokeuser->username)){ $add[] = "nombre"; }
			if($pokeuser->lvl == 1){ $add[] = "nivel actual"; }
			$text .= implode(" y ", $add) ."*.\n";

			if(empty($pokeuser->username)){ $text .= ":triangle-right: *Me llamo ...*\n"; }
			if($pokeuser->lvl == 1){ $text .= ":triangle-right: *Soy nivel ...*\n"; }

			$text .= "Cuando lo hayas dicho, *vuelve a enviarme la captura.*";
			$telegram->send
				->notification(TRUE)
				->chat($userid)
				->text($telegram->emoji($text), TRUE)
				->keyboard()->hide(TRUE)
			->send();
			$pokemon->step($userid, NULL);
			return -1;
		}

		// Comprobar si ya hay otra imagen previamente en cola.
		$cooldown = $pokemon->settings($userid, 'verify_cooldown');
		if(!empty($cooldown) and $cooldown > time()){
			$this->telegram->send
				->chat($userid)
				->text($this->telegram->emoji(":warning: ") ."¡Para el carro! Ya me has mandado una foto. Esperate a que la compruebe, no me des más faena...")
			->send();
			$pokemon->step($userid, NULL);
			return -1;
		}

		// Comprobar si ya me ha mandado la misma foto.
		$images = $pokemon->settings($userid, 'verify_images');
		if(!empty($images)){
			$images = unserialize($images);
			if(in_array($this->telegram->photo(), array_values($images))){
				$this->telegram->send
					->chat($userid)
					->text($this->telegram->emoji(":times: ") ."¡Esta foto ya me la has mandado! Haz otra foto nueva, y asegúrate de que cumple los requisitos.")
				->send();
				return -1;
			}
		}

		if(!is_array($images)){ $images = array(); }
		$images[time()] = $this->telegram->photo();

		// Cooldown +18h
		$pokemon->settings($userid, 'verify_cooldown', (time() + 64800));
		$pokemon->settings($userid, 'verify_images', serialize($images));

		$telegram->send
			->message(TRUE)
			->chat(TRUE)
			->forward_to("-197822813")
		->send();

		$telegram->send
			->notification(TRUE)
			->chat("-197822813")
			->text("Validar " .$userid ." @" .$pokeuser->username ." L" .$pokeuser->lvl ." " .$pokeuser->team)
			->inline_keyboard()
				->row()
					->button($telegram->emoji(":ok:"), "te valido " .$userid, "TEXT")
					->button($telegram->emoji(":times:"), "no te valido " .$userid, "TEXT")
				->end_row()
			->show()
		->send();

		$telegram->send
			->notification(TRUE)
			->chat($userid)
			->keyboard()->hide(TRUE)
			->text($this->telegram->emoji(":ok: ") ."¡Enviado correctamente! El proceso de validar puede tardar un tiempo.")
		->send();

		$pokemon->step($userid, NULL);
		return -1;
	}
}

if($telegram->text_command("register")){
	if($pokemon->command_limit("register", $telegram->chat->id, $telegram->message, 10)){ return -1; }

    $pokeuser = $pokemon->user($telegram->user->id);
    if($pokeuser->verified){ return; }

    $telegram->send
        ->text($telegram->emoji(":warning:") ." ¿Entiendo que quieres *validarte*?", TRUE)
        ->inline_keyboard()
            ->row_button("Validar", "quiero validarme", TRUE)
        ->show()
    ->send();
    return;
}

if($telegram->text_has(["Te valido", "No te valido"], TRUE) && $telegram->words() <= 4){
    $pokeuser = $pokemon->user($telegram->user->id);
    if(!$pokeuser->authorized){ return; }
    $target = NULL;
    if($telegram->words() == 2 && $telegram->has_reply){
        $target = $telegram->reply_user->id;
        if($telegram->reply_is_forward && $telegram->reply_user->id != $telegram->reply->forward_from->id){
            $target = $telegram->reply->forward_from['id'];
        }
    }elseif(in_array($telegram->words(), [3,4])){
        $target = $telegram->last_word(TRUE);
        if($target[0] == "@"){ $target = substr($target, 1); }
        $target = $pokemon->find_users($target);
        if($target == FALSE or count($target) > 1){ return; }
        $target = $target[0]['telegramid'];
    }

	$poketarget = $pokemon->user($target);
	$str = "Validar " .$poketarget->telegramid ." @" .$poketarget->username
			." L" .$poketarget->lvl ." " .$poketarget->team;
    if($pokemon->user_verified($target)){
        $telegram->answer_if_callback($telegram->emoji("¡Ya está validado! :ok:"), TRUE);
        $telegram->send
            ->message(TRUE)
            ->chat(TRUE)
            ->text($str .$telegram->emoji(" :ok:"))
        ->edit('text');
        return;
    }

	$pokemon->settings($target, 'verify_cooldown', 'DELETE');

	if($telegram->text_has("no")){
		$telegram->send
            ->notification(TRUE)
            ->chat($target)
            ->text($telegram->emoji(":times: ") ."La validación no es correcta. Revisa la captura de pantalla, y envíala tal y como se pide.")
        ->send();

		if($telegram->callback){
            $telegram->answer_if_callback("");
            $telegram->send
                ->message(TRUE)
                ->chat(TRUE)
                ->text($str .$telegram->emoji(" :times:"))
            ->edit('text');
        }

		return -1;
	}

    if($pokemon->verify_user($telegram->user->id, $target)){
        $telegram->send
            ->notification(FALSE)
            ->text( $telegram->emoji(":green-check:") )
        ->send();

        if($telegram->callback){
            $telegram->answer_if_callback("¡De acuerdo, validado!");
            $telegram->send
                ->message(TRUE)
                ->chat(TRUE)
                ->text($str .$telegram->emoji(" :ok:"))
            ->edit('text');
        }

        $telegram->send
            ->notification(TRUE)
            ->chat($target)
            ->text("Enhorabuena, estás validado! " .$telegram->emoji(":green-check:"))
        ->send();

        if($pokemon->step($target) == "SCREENSHOT_VERIFY"){
            $pokemon->step($target, NULL);
        }
    }
}

// Validar usuario
elseif(
    $telegram->text_contains(["oak", "profe", "quiero", "como"]) &&
    $telegram->text_contains(["validame", "valida", "validarme", "validarse", "válido", "verificarme", "verifico"]) &&
    $telegram->words() <= 7
){
    if($telegram->is_chat_group()){
		if($pokemon->command_limit("validar", $telegram->chat->id, $telegram->message, 7)){ return -1; }

        $res = $telegram->send
            ->notification(TRUE)
            ->chat($telegram->user->id)
            ->text("Hola, " .$telegram->user->first_name ."!")
        ->send();

        if(!$res){
            $telegram->send
                ->notification(FALSE)
                // ->reply_to(TRUE)
                ->text($telegram->emoji(":times: Pídemelo por privado, por favor."))
                ->inline_keyboard()
                    ->row_button("Validar perfil", "quiero validarme", TRUE)
                ->show()
            ->send();
            return;
        }
    }

    $pokeuser = $pokemon->user($telegram->user->id);

    if($pokeuser->verified){
        $telegram->send
            ->notification(TRUE)
            ->chat($telegram->user->id)
            ->text("¡Ya estás verificado! " .$telegram->emoji(":green-check:"))
        ->send();
        return;
    }

    $text = "Para validarte, necesito que me envies una *captura de tu perfil Pokemon GO.* "
            ."La captura tiene que cumplir las siguientes condiciones:\n\n"
            .":triangle-right: Tiene que verse la hora de tu móvil, y tienes que enviarlo en un márgen de 7 minutos.\n"
            .":triangle-right: Tiene que aparecer tu nombre de entrenador y color.\n"
			.":triangle-right: Tienes que tener una mascota que se llame *Oak*. Luego puedes cambiarle el nombre.\n"
            // .":triangle-right: Si te has cambiado de nombre, avisa a @duhow para tenerlo en cuenta.\n"
            // .":triangle-right: Si no tienes nombre puesto, *cancela el comando* y dime cómo te llamas.\n"
            ."\nCuando haya confirmado la validación, te avisaré por aquí.\n\n"
            ."Tus datos son: ";

    $color = ['Y' => ':heart-yellow:', 'R' => ':heart-red:', 'B' => ':heart-blue:'];

    $text .= (empty($pokeuser->username) ? "Sin nombre" : "@" .$pokeuser->username) ." L" .$pokeuser->lvl ." " .$color[$pokeuser->team];

    $telegram->send
        ->notification(TRUE)
        ->chat($telegram->user->id)
        ->text($telegram->emoji($text), TRUE)
        ->keyboard()
            ->row_button("Cancelar")
        ->show(TRUE, TRUE)
    ->send();

    if(empty($pokeuser->username) or $pokeuser->lvl == 1){
		$text = "Antes de validarte, necesito saber tu *";
		$add = array();
		if(empty($pokeuser->username)){ $add[] = "nombre"; }
		if($pokeuser->lvl == 1){ $add[] = "nivel actual"; }
		$text .= implode(" y ", $add) ."*.\n";

		if(empty($pokeuser->username)){ $text .= ":triangle-right: *Me llamo ...*\n"; }
		if($pokeuser->lvl == 1){ $text .= ":triangle-right: *Soy nivel ...*\n"; }

		$text .= "Cuando lo hayas dicho, *vuelve a enviarme la captura.*";

        $telegram->send
            ->notification(TRUE)
            ->chat($telegram->user->id)
            ->text($telegram->emoji($text), TRUE)
            ->keyboard()->hide(TRUE)
        ->send();
		$pokemon->step($telegram->user->id, NULL);
        return -1; // Kill process for STEP
    }

    $pokemon->step($telegram->user->id, 'SCREENSHOT_VERIFY');
    return;
}

elseif($telegram->text_command("ocrv") && $this->telegram->user->id == $this->config->item('creator') && $this->telegram->has_reply){
	if(!isset($this->telegram->reply->photo)){ return; }
	$photo = array_pop($this->telegram->reply->photo);
	$url = $this->telegram->download($photo['file_id']);

	$temp = tempnam("/tmp", "tgphoto");
	file_put_contents($temp, file_get_contents($url));

	$out = shell_exec("convert $temp +dither -posterize 2 -crop 20x20%+600+50 -define histogram:unique-colors=true -format %c histogram:info:-");

	$colors = ['Y' => 'yellow', 'R' => 'red', 'B' => 'cyan'];
	$csel = NULL;
	foreach($colors as $team => $color){
		if(strpos($out, $color) !== FALSE){
			$csel = $team; break;
		}
	}

	$str = ":warning: Color no detectado.";
	if(!empty($csel)){
		$u = $pokemon->user($this->telegram->reply_target('forward')->id);
		$str = "Color detectado $csel, equipo " .$u->team ." - " .($csel == $u->team ? ":ok:" : ":times:");
	}
	$str = $this->telegram->emoji($str);

	$this->telegram->send
		->text($str)
	->send();

	unlink($temp);

	return -1;
}

?>
