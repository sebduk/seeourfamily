<?php
/**
 * labels.php - Multilingual labels for See Our Family
 *
 * Replaces Include/Label.asp which used a VBScript Select Case block
 * to set ~50 string variables per language (ENG, FRA, ESP, ITA, POR, DEU, NLD).
 *
 * Usage:
 *   $L = get_labels('FRA');
 *   echo $L['menu_home'];  // "<b>a</b>ccueil"
 */

function get_labels(string $lang): array
{
    $labels = [];

    switch ($lang) {

    case 'FRA':
        $labels['menu_home']       = '<b>a</b>ccueil';
        $labels['menu_genealogy']  = '<b>g</b>&eacute;n&eacute;alogie par';
        $labels['menu_names']      = '<b>n</b>oms';
        $labels['menu_years']      = '<b>a</b>nn&eacute;es';
        $labels['menu_calendar']   = '<b>a</b>nniversaires';
        $labels['menu_pictures']   = '<b>p</b>hotos';
        $labels['menu_documents']  = '<b>d</b>ocuments';
        $labels['menu_messages']   = '<b>m</b>essages';
        $labels['menu_help']       = '<b>a</b>ide';
        $labels['menu_login']      = '<b>l</b>ogin';
        $labels['menu_admin']      = '<b>a</b>dmin';
        $labels['menu_update']     = '<b>m</b>odifier les';
        $labels['menu_people']     = '<b>p</b>ersonnes';
        $labels['menu_couples']    = '<b>c</b>ouples';
        $labels['menu_comments']   = '<b>c</b>ommentaires';

        $labels['last_updates']    = 'Derni&egrave;res mises &agrave; jour';
        $labels['full_ascendance'] = 'Ascendance compl&egrave;te';
        $labels['full_descendance']= 'Descendance compl&egrave;te';
        $labels['classic']         = 'Classique';
        $labels['horizontal']      = 'Horizontale';
        $labels['vertical']        = 'Verticale';
        $labels['table']           = 'Table';
        $labels['excel']           = 'Excel';
        $labels['heavy_warning']   = 'Versions lourdes &agrave; g&eacute;n&eacute;rer.<br>Soyez patient!';

        $labels['born_m']          = 'N&eacute; &agrave;';
        $labels['born_f']          = 'N&eacute;e &agrave;';
        $labels['died_m']          = 'D&eacute;c&eacute;d&eacute; &agrave;';
        $labels['died_f']          = 'D&eacute;c&eacute;d&eacute;e &agrave;';
        $labels['search']          = 'Rechercher';
        $labels['close']           = 'Fermer';
        $labels['couple']          = 'Couple';
        $labels['biography']       = 'Biographie';
        $labels['comments']        = 'Commentaires';
        $labels['pictures']        = 'Photos';
        $labels['documents']       = 'Documents';
        $labels['with']            = 'Avec';
        $labels['back']            = 'Retour';
        $labels['top']             = 'haut';

        $labels['individuals']     = 'Personnes';
        $labels['calendar_warn']   = 'Seules les personnes dont la date de naissance exacte est connue apparaissent dans cette liste';
        $labels['pictures_all']    = 'Toutes les Photos';
        $labels['login_message']   = 'Loggez pour modifier le menu et avoir acc&egrave;s aux modifications.<br>(vous serez automatiquement d&eacute;logg&eacute;, si vous fermez votre navigateur)';
        $labels['password']        = 'Mot de Passe';

        $labels['file_name']       = 'Nom de Fichier';
        $labels['date']            = 'Date';
        $labels['participants']    = 'Participants';
        $labels['size']            = 'Taille';
        $labels['uploaded']        = 'Ajout&eacute; le';

        $labels['msg_all']         = 'Tous les Messages';
        $labels['msg_personal']    = 'Messages Personnels';
        $labels['msg_subject']     = 'Sujet';
        $labels['msg_from']        = 'De';
        $labels['msg_to']          = 'A';
        $labels['msg_send']        = 'Envoyer';
        $labels['msg_email']       = 'Email';

        $labels['months'] = [
            1 => 'janvier', 2 => 'f&eacute;vrier', 3 => 'mars',
            4 => 'avril', 5 => 'mai', 6 => 'juin',
            7 => 'juillet', 8 => 'ao&ucirc;t', 9 => 'septembre',
            10 => 'octobre', 11 => 'novembre', 12 => 'd&eacute;cembre',
        ];
        break;

    case 'ESP':
        $labels['menu_home']       = '<b>i</b>nicio';
        $labels['menu_genealogy']  = '<b>g</b>enealogia por';
        $labels['menu_names']      = '<b>n</b>ombres';
        $labels['menu_years']      = '<b>a</b>&ntilde;os';
        $labels['menu_calendar']   = '<b>c</b>umplea&ntilde;os';
        $labels['menu_pictures']   = '<b>f</b>otos';
        $labels['menu_documents']  = '<b>d</b>ocumentos';
        $labels['menu_messages']   = '<b>m</b>ensajes';
        $labels['menu_help']       = '<b>a</b>yuda';
        $labels['menu_login']      = '<b>c</b>onexi&oacute;n';
        $labels['menu_admin']      = '<b>a</b>dmin';
        $labels['menu_update']     = '<b>m</b>odificar las';
        $labels['menu_people']     = '<b>p</b>ersonas';
        $labels['menu_couples']    = '<b>p</b>arejas';
        $labels['menu_comments']   = '<b>c</b>omentarios';

        $labels['born_m']          = 'Nacido en';
        $labels['born_f']          = 'Nacida en';
        $labels['died_m']          = 'Fallecido en';
        $labels['died_f']          = 'Fallecida en';
        $labels['search']          = 'Busca';
        $labels['close']           = 'Cerrar';
        $labels['couple']          = 'Pareja';
        $labels['biography']       = 'Biografia';
        $labels['comments']        = 'Comentarios';
        $labels['pictures']        = 'Fotos';
        $labels['documents']       = 'Documentos';
        $labels['with']            = 'Con';
        $labels['back']            = 'Anterior';
        $labels['top']             = 'top';
        $labels['individuals']     = 'Personas';
        $labels['password']        = 'contrase&ntilde;a';
        $labels['pictures_all']    = 'Todas las Fotos';
        $labels['login_message']   = 'conectarse para modificar el menu y ganar acceso a las modificaciones.';

        $labels['months'] = [
            1 => 'enero', 2 => 'febrero', 3 => 'marzo',
            4 => 'abril', 5 => 'mayo', 6 => 'junio',
            7 => 'julio', 8 => 'agosto', 9 => 'septiembre',
            10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
        ];
        break;

    case 'ITA':
        $labels['menu_home']       = '<b>h</b>ome';
        $labels['menu_genealogy']  = '<b>g</b>enealogia per';
        $labels['menu_names']      = '<b>n</b>ome';
        $labels['menu_years']      = '<b>a</b>nno';
        $labels['menu_calendar']   = '<b>c</b>ompleanno';
        $labels['menu_pictures']   = '<b>f</b>oto';
        $labels['menu_documents']  = '<b>d</b>ocumento';
        $labels['menu_messages']   = '<b>m</b>essaggio';
        $labels['menu_help']       = '<b>a</b>iuto';
        $labels['menu_login']      = '<b>l</b>ogin';
        $labels['menu_admin']      = '<b>a</b>dmin';
        $labels['menu_update']     = '<b>a</b>ggiornare le';
        $labels['menu_people']     = '<b>p</b>ersone';
        $labels['menu_couples']    = '<b>c</b>oppie';
        $labels['menu_comments']   = '<b>c</b>ommentari';

        $labels['born_m']          = 'Nato a';
        $labels['born_f']          = 'Nata a';
        $labels['died_m']          = 'Morto a';
        $labels['died_f']          = 'Morta a';
        $labels['search']          = 'Cerca';
        $labels['couple']          = 'Coppia';
        $labels['biography']       = 'Biografia';
        $labels['comments']        = 'Commentari';
        $labels['pictures']        = 'Foto';
        $labels['documents']       = 'Documenti';
        $labels['with']            = 'Con';
        $labels['back']            = 'Anteriore';
        $labels['top']             = 'top';
        $labels['individuals']     = 'Persone';
        $labels['password']        = 'Password';

        $labels['months'] = [
            1 => 'gennaio', 2 => 'febbraio', 3 => 'marzo',
            4 => 'aprile', 5 => 'maggio', 6 => 'giugno',
            7 => 'luglio', 8 => 'agosto', 9 => 'settembre',
            10 => 'ottobre', 11 => 'novembre', 12 => 'dicembre',
        ];
        break;

    case 'POR':
        $labels['menu_home']       = '<b>h</b>ome';
        $labels['menu_genealogy']  = '<b>g</b>enealogia por';
        $labels['menu_names']      = '<b>n</b>omes';
        $labels['menu_years']      = '<b>a</b>nhos';
        $labels['menu_calendar']   = '<b>a</b>nivers&aacute;rios';
        $labels['menu_pictures']   = '<b>f</b>otos';
        $labels['menu_documents']  = '<b>d</b>ocumentos';
        $labels['menu_messages']   = '<b>m</b>ensagens';
        $labels['menu_help']       = '<b>a</b>juda';
        $labels['menu_login']      = '<b>l</b>ogin';
        $labels['menu_admin']      = '<b>a</b>dmin';
        $labels['menu_people']     = '<b>p</b>essoas';
        $labels['menu_couples']    = '<b>c</b>asais';
        $labels['menu_comments']   = '<b>c</b>oment&aacute;rios';

        $labels['born_m']          = 'Nascido em';
        $labels['born_f']          = 'Nascida em';
        $labels['died_m']          = 'Falecido em';
        $labels['died_f']          = 'Falecida em';
        $labels['password']        = 'Senha';

        $labels['months'] = [
            1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Mar&ccedil;o',
            4 => 'Abril', 5 => 'Maio', 6 => 'Junho',
            7 => 'Julho', 8 => 'Agosto', 9 => 'Setembro',
            10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro',
        ];
        break;

    case 'DEU':
        $labels['menu_home']       = '<b>h</b>ome';
        $labels['menu_genealogy']  = '<b>G</b>enealogie pro';
        $labels['menu_names']      = '<b>N</b>amen';
        $labels['menu_years']      = '<b>J</b>ahre';
        $labels['menu_calendar']   = '<b>G</b>eburtstag';
        $labels['menu_pictures']   = '<b>B</b>ilder';
        $labels['menu_documents']  = '<b>D</b>okumenten';
        $labels['menu_messages']   = '<b>m</b>essages';
        $labels['menu_help']       = '<b>H</b>ilfe';
        $labels['menu_login']      = '<b>L</b>ogin';
        $labels['menu_admin']      = '<b>a</b>dmin';
        $labels['menu_people']     = '<b>L</b>eute';
        $labels['menu_couples']    = '<b>P</b>aare';
        $labels['menu_comments']   = '<b>A</b>nmerkungen';

        $labels['born_m']          = 'Geboren in';
        $labels['born_f']          = 'Geboren in';
        $labels['died_m']          = 'Gestorben in';
        $labels['died_f']          = 'Gestorben in';
        $labels['search']          = 'Suche';
        $labels['biography']       = 'Biographie';
        $labels['comments']        = 'Anmerkungen';
        $labels['pictures']        = 'Bilder';
        $labels['documents']       = 'Dokumenten';
        $labels['password']        = 'Kennwort';
        $labels['pictures_all']    = 'Alle Bilder';
        $labels['individuals']     = 'Individuen';

        $labels['months'] = [
            1 => 'Januar', 2 => 'Februar', 3 => 'M&auml;rz',
            4 => 'April', 5 => 'Mai', 6 => 'Juni',
            7 => 'Juli', 8 => 'August', 9 => 'September',
            10 => 'Oktober', 11 => 'November', 12 => 'Dezember',
        ];
        break;

    case 'NLD':
        $labels['menu_home']       = '<b>h</b>ome';
        $labels['menu_genealogy']  = '<b>g</b>enealogie per';
        $labels['menu_names']      = '<b>n</b>amen';
        $labels['menu_years']      = '<b>g</b>eboortejaren';
        $labels['menu_calendar']   = '<b>v</b>erjaardagen';
        $labels['menu_pictures']   = '<b>a</b>fbeeldingen';
        $labels['menu_documents']  = '<b>d</b>ocumenten';
        $labels['menu_messages']   = '<b>b</b>erichten';
        $labels['menu_help']       = '<b>h</b>ulp';
        $labels['menu_login']      = '<b>l</b>ogin';
        $labels['menu_admin']      = '<b>a</b>dmin';
        $labels['menu_people']     = '<b>m</b>ensen';
        $labels['menu_couples']    = '<b>p</b>aren';
        $labels['menu_comments']   = '<b>c</b>ommentaren';

        $labels['born_m']          = 'Geboren in';
        $labels['born_f']          = 'Geboren in';
        $labels['died_m']          = 'Overlijdt in';
        $labels['died_f']          = 'Overlijdt in';
        $labels['search']          = 'Zoek';
        $labels['biography']       = 'Biografie';
        $labels['comments']        = 'Commentaren';
        $labels['pictures']        = 'Afbeeldingen';
        $labels['documents']       = 'Documenten';
        $labels['password']        = 'Wachtwoord';
        $labels['pictures_all']    = 'Alle afbeeldingen';
        $labels['individuals']     = 'Personen';

        $labels['months'] = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maart',
            4 => 'April', 5 => 'Mai', 6 => 'Juni',
            7 => 'Juli', 8 => 'Augustus', 9 => 'September',
            10 => 'Oktober', 11 => 'November', 12 => 'December',
        ];
        break;
    }

    // English defaults — used for ENG and as fallback for missing keys
    $defaults = [
        'menu_home'       => '<b>h</b>ome',
        'menu_genealogy'  => '<b>g</b>enealogy by',
        'menu_names'      => '<b>n</b>ames',
        'menu_years'      => '<b>y</b>ears',
        'menu_calendar'   => '<b>b</b>irthdays',
        'menu_pictures'   => '<b>p</b>ictures',
        'menu_documents'  => '<b>d</b>ocuments',
        'menu_messages'   => '<b>m</b>essages',
        'menu_help'       => '<b>h</b>elp',
        'menu_login'      => '<b>l</b>ogin',
        'menu_admin'      => '<b>a</b>dmin',
        'menu_update'     => '<b>u</b>pdate',
        'menu_people'     => '<b>p</b>eople',
        'menu_couples'    => '<b>c</b>ouples',
        'menu_comments'   => '<b>c</b>omments',

        'last_updates'    => 'Last updates',
        'full_ascendance' => 'View All Parents',
        'full_descendance'=> 'View All Children',
        'classic'         => 'Classic',
        'horizontal'      => 'Horizontal',
        'vertical'        => 'Vertical',
        'table'           => 'Table',
        'excel'           => 'Excel',
        'heavy_warning'   => 'These versions require heavier processing.<br>Be patient!',

        'born_m'          => 'Born in',
        'born_f'          => 'Born in',
        'died_m'          => 'Died in',
        'died_f'          => 'Died in',
        'search'          => 'Search',
        'close'           => 'Close',
        'couple'          => 'Couple',
        'biography'       => 'Biography',
        'comments'        => 'Comments',
        'pictures'        => 'Pictures',
        'documents'       => 'Documents',
        'with'            => 'With',
        'back'            => 'Back',
        'top'             => 'top',

        'individuals'     => 'Individuals',
        'calendar_warn'   => 'Only individuals for whom a precise birthdate is known are listed',
        'pictures_all'    => 'View all pictures',
        'login_message'   => 'Login to broaden the menu and gain access to the update functions.<br>(you will be automatically logged off if you close your browser)',
        'password'        => 'Password',

        'file_name'       => 'File Name',
        'date'            => 'Date',
        'participants'    => 'Participants',
        'size'            => 'Size',
        'uploaded'        => 'Uploaded',

        'msg_all'         => 'All Messages',
        'msg_personal'    => 'Personal Messages',
        'msg_subject'     => 'Subject',
        'msg_from'        => 'From',
        'msg_to'          => 'To',
        'msg_send'        => 'Send',
        'msg_email'       => 'Email',
        'warn_subject'    => 'Please enter a Subject',
        'warn_from'       => 'Please enter your name in From',
        'warn_email'      => 'Please enter an Email',
        'warn_body'       => 'Please enter a Message',
        'warn_to'         => 'Please select one or more people to send the message To',

        'months' => [
            1 => 'January', 2 => 'February', 3 => 'March',
            4 => 'April', 5 => 'May', 6 => 'June',
            7 => 'July', 8 => 'August', 9 => 'September',
            10 => 'October', 11 => 'November', 12 => 'December',
        ],
    ];

    return array_merge($defaults, $labels);
}
