<?php
require('postmark.php');

//setup database
try {
        $dbh = new PDO("mysql:host=localhost;dbname=test" , 'root', 'likes69');
    }
catch(PDOException $e)
    {
        echo $e->getMessage();
    }

//loop throught the cases table
$q = $dbh->prepare("select * from cases");
$q->execute();
$result = $q->fetchAll();
foreach ($result as $r) {
    // $file is our stored version of the docket master
    // $temp_file is file we get now to check for changes
    $file = 'files/' . $r['id'];
    $temp_file = 'files/' . $r['id'] . "_tmp";

    //File should be created at sign up, but if for some reason
    //not, do it now.
    if(!file_exists($file))//this needs to be id, not case number
    {
        $ch = curl_init($r['url']);
        $fp = fopen($file, "w");
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_exec($ch);
        curl_close($ch);
        fclose($fp);
    }
    else //get the new file and do the diff
    {
        $ch = curl_init($r['url']);
        $fp = fopen($temp_file, "w");
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_exec($ch);
        curl_close($ch);
        fclose($fp);

        $lines = array();
        exec("diff $file $temp_file",$lines); 

        //Now remove the first four items from the lines array; docket master 
        //gives you a new time every time docket is called, creating false positives
        $lines_clean = array_slice($lines,4);

        if (count($lines_clean) > 0)
        {
            //now take off the cruft and output a string
            foreach ($lines_clean as &$line) {
                $line = trim($line,">");
            }
            
            $diff = implode("\n",(array_slice($lines_clean,1,-1)));

            //notify user - use postmark app
            $user = $dbh->prepare('select email from users where username = ?');
            $user->bindParam(1,$r['tracked_by']);
            $user->execute();
            $u = $user->fetch();
            $case_name = ucwords(strtolower($r['name']));
            $subject = "DocketMinder Change: $case_name";
            $message = "DocketMinder has detected a change in the $case_name case.\n\n"
            . $diff . "\n\n To view this docket: " . $r['url'];

            $postmark = new Postmark("c3dda16b-9ab5-484b-9859-f48cfcc352c5","bot@loyolalawtech.org");
            $mail = $postmark->to($u['email'])
            ->subject($subject)
            ->plain_message($message)
            ->send();
            //update db (tracked date and change date) 
            $update = $dbh->prepare('UPDATE cases SET last_tracked = NOW(),last_changed = NOW()  WHERE id = ?');
        }
        else
        {
            //update db (only track date) 
            $update = $dbh->prepare('UPDATE cases SET last_tracked = NOW() WHERE id = ?');
        }

        //overwrite the old file with new file
        rename($temp_file, $file);

        $update->bindParam(1,$r['id']);
        $update->execute();
    }
}
