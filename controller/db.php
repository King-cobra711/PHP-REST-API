<?php 


class DB {
    private static $writeDBConnection;
    private static $readDBConnection;

    public static function connectWriteDB(){
        if(self::$writeDBConnection === null){
            // creates database connection. Passes the host (localhost), database name (taskdb) and charset.
            self::$writeDBConnection = new PDO('mysql:host=localhost;dbname=tasksdb;utf-8', 'admin', 'Password');
            // set attributes on the connection. Set the error mode to use exceptions (catch exceptions etc for error handling)
            self::$writeDBConnection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            // prepared statements allows you to creat sql code and then put in placeholders. MySQL allows you to use prepared statements natively and thus this is set to false.
            self::$writeDBConnection->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

        }
        return self::$writeDBConnection;
    }
    public static function connectReadDB(){
        if(self::$readDBConnection === null){
            // creates database connection. Passes the host (localhost), database name (taskdb) and charset.
            self::$readDBConnection = new PDO('mysql:host=localhost;dbname=tasksdb;utf-8', 'admin', 'Password');
            // set attributes on the connection. Set the error mode to use exceptions (catch exceptions etc for error handling)
            self::$readDBConnection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            // prepared statements allows you to creat sql code and then put in placeholders. MySQL allows you to use prepared statements natively and thus this is set to false.
            self::$readDBConnection->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

        }
        return self::$readDBConnection;
    }

}

?>