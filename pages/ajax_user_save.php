<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requirePermission('lph.users.manage');

if($_SERVER['REQUEST_METHOD']!=='POST')jsonResponse(false,'Invalid request method.');
requireCsrf();

$pdo=db();
$id=(int)($_POST['id']??0);
$fullName=clean($_POST['full_name']??'');
$username=clean($_POST['username']??'');
$email=strtolower(clean($_POST['email']??''));
$phone=clean($_POST['phone']??'');
$roleId=(int)($_POST['role_id']??0);
$officeId=(int)($_POST['office_id']??0)?:null;
$status=clean($_POST['status']??'Active');
$accessStatus=clean($_POST['lph_access_status']??'Active');
$accessLevel=clean($_POST['access_level']??'Standard');
$password=(string)($_POST['password']??'');

$errors=[];

if($fullName==='')$errors[]='Full name is required.';
if($email===''||!filter_var($email,FILTER_VALIDATE_EMAIL))$errors[]='A valid email address is required.';
if($roleId<=0)$errors[]='Select a role.';
if(!in_array($status,['Active','Inactive'],true))$errors[]='Invalid account status.';
if(!in_array($accessStatus,['Active','Inactive'],true))$errors[]='Invalid LPH access status.';
if(!in_array($accessLevel,['Administrator','Staff','Committee','Stakeholder','Standard'],true))$errors[]='Invalid access level.';
if($id===0&&$password==='')$errors[]='Password is required for a new user.';

if($password!==''){
    if(strlen($password)<10)$errors[]='Password must be at least 10 characters.';
    if(!preg_match('/[A-Z]/',$password))$errors[]='Password must contain an uppercase letter.';
    if(!preg_match('/[a-z]/',$password))$errors[]='Password must contain a lowercase letter.';
    if(!preg_match('/[0-9]/',$password))$errors[]='Password must contain a number.';
}

if($errors)jsonResponse(false,implode(' ',$errors));

$role=$pdo->prepare('SELECT name FROM roles WHERE id=:id');
$role->execute([':id'=>$roleId]);
$roleName=$role->fetchColumn();
if(!$roleName)jsonResponse(false,'Selected role does not exist.');

if($officeId){
    $office=$pdo->prepare("SELECT COUNT(*) FROM offices WHERE id=:id AND status='Active'");
    $office->execute([':id'=>$officeId]);
    if((int)$office->fetchColumn()===0)jsonResponse(false,'Selected office does not exist or is inactive.');
}

$dup=$pdo->prepare(
 'SELECT id FROM users
  WHERE deleted_at IS NULL
    AND id<>:id
    AND (LOWER(email)=LOWER(:email) OR (:username<>"" AND LOWER(username)=LOWER(:username)))
  LIMIT 1'
);
$dup->execute([':id'=>$id,':email'=>$email,':username'=>$username]);
if($dup->fetch())jsonResponse(false,'Another active user already uses this email address or username.');

$existing=null;
if($id>0){
    $q=$pdo->prepare(
      'SELECT u.*,r.name role_name FROM users u JOIN roles r ON r.id=u.role_id
       WHERE u.id=:id AND u.deleted_at IS NULL'
    );
    $q->execute([':id'=>$id]);$existing=$q->fetch();
    if(!$existing)jsonResponse(false,'User not found.');

    if($id===(int)currentUserId()){
        if($status!=='Active'||$accessStatus!=='Active'){
            jsonResponse(false,'You cannot deactivate your own account or Public Hearing access.');
        }
        if($existing['role_name']==='Administrator' && $roleName!=='Administrator'){
            jsonResponse(false,'You cannot remove your own Administrator role.');
        }
    }
}

function activeAdministratorCountForUserSave(PDO $pdo): int {
    return (int)$pdo->query(
      "SELECT COUNT(*)
       FROM users u JOIN roles r ON r.id=u.role_id
       WHERE r.name='Administrator'
         AND u.status='Active'
         AND u.deleted_at IS NULL"
    )->fetchColumn();
}

if(
    $existing
    && $existing['role_name']==='Administrator'
    && (
        $roleName!=='Administrator'
        || $status!=='Active'
        || $accessStatus!=='Active'
    )
    && activeAdministratorCountForUserSave($pdo)<=1
){
    jsonResponse(false,'At least one active Administrator must remain available.');
}

try{
    $pdo->beginTransaction();

    if($id>0){
        $sql='UPDATE users
              SET username=:username,full_name=:name,email=:email,phone=:phone,
                  role_id=:role,office_id=:office,status=:status,
                  deleted_at=NULL,updated_at=NOW()';
        $params=[
          ':username'=>$username?:null,':name'=>$fullName,':email'=>$email,
          ':phone'=>$phone?:null,':role'=>$roleId,':office'=>$officeId,
          ':status'=>$status,':id'=>$id
        ];

        if($password!==''){
            $sql.=',password=:password';
            $params[':password']=password_hash($password,PASSWORD_DEFAULT);
        }

        $sql.=' WHERE id=:id';
        $pdo->prepare($sql)->execute($params);
        $message='User updated successfully.';
    }else{
        $pdo->prepare(
          'INSERT INTO users
           (username,full_name,email,phone,password,role_id,office_id,status,
            created_at,last_login_at,updated_at,deleted_at)
           VALUES
           (:username,:name,:email,:phone,:password,:role,:office,:status,
            NOW(),NULL,NOW(),NULL)'
        )->execute([
          ':username'=>$username?:null,':name'=>$fullName,':email'=>$email,
          ':phone'=>$phone?:null,':password'=>password_hash($password,PASSWORD_DEFAULT),
          ':role'=>$roleId,':office'=>$officeId,':status'=>$status
        ]);
        $id=(int)$pdo->lastInsertId();
        $message='User created successfully.';
    }

    $pdo->prepare(
      'DELETE FROM user_roles WHERE user_id=:user AND is_primary=1'
    )->execute([':user'=>$id]);

    $pdo->prepare(
      'INSERT IGNORE INTO user_roles
       (user_id,role_id,is_primary,assigned_by,assigned_at)
       VALUES(:user,:role,1,:assigned_by,NOW())'
    )->execute([
      ':user'=>$id,':role'=>$roleId,':assigned_by'=>currentUserId()
    ]);

    $pdo->prepare(
      'INSERT INTO user_system_access
       (user_id,system_id,access_level,status,granted_by,granted_at,updated_at)
       VALUES(:user,:system,:level,:status,:granted_by,NOW(),NOW())
       ON DUPLICATE KEY UPDATE
          access_level=VALUES(access_level),
          status=VALUES(status),
          granted_by=VALUES(granted_by),
          updated_at=NOW()'
    )->execute([
      ':user'=>$id,':system'=>lphSystemId(),':level'=>$accessLevel,
      ':status'=>$accessStatus,':granted_by'=>currentUserId()
    ]);

    if($id===(int)currentUserId()){
        $_SESSION['full_name']=$fullName;
        $_SESSION['email']=$email;
        $_SESSION['role_id']=$roleId;
        $_SESSION['role_name']=$roleName;
    }

    logActivity(
      currentUserId(),
      $existing?'Update User':'Create User',
      "{$message} User #{$id} {$fullName}; role={$roleName}; LPH access={$accessStatus}."
    );

    $pdo->commit();

    jsonResponse(true,$message,['id'=>$id]);
}catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    error_log('User save error: '.$e->getMessage());
    jsonResponse(false,APP_DEBUG?$e->getMessage():'Unable to save user.');
}
