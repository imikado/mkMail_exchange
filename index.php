<?php
set_time_limit(0);
include('connexion.php');
include('plugin_date.php');

include('plugin_datetime.php');

//Webservice
$sUrl=$sUrlMkmail.'webservice.php?WSDL';

$oSoap = new SoapClient($sUrl, array("trace" => 1, "exception" => 0,'cache_wsdl' => WSDL_CACHE_NONE)); 
$sDate=$oSoap->getLastDateIntegration();

//----------------------------------------------------------------------------

if($sDate){
	$oDate=new plugin_date($sDate);
}else{
	$oDate=new plugin_date('2014-07-01');
}
//version exchange
$version= ExchangeWebServices::VERSION_2007_SP1;


//----------------------------------------------------------------------------


function __autoload($class_name)
{
    // Start from the base path and determine the location from the class name,
    $base_path = 'php-ews-master';
    $include_file = $base_path . '/' . str_replace('_', '/', $class_name) . '.php';

    return (file_exists($include_file) ? require_once $include_file : false);
}

function detailMessage($message_id){
	
	global $ews;
	
	// Build the request for the parts.
	$request = new EWSType_GetItemType();
	$request->ItemShape = new EWSType_ItemResponseShapeType();
	$request->ItemShape->BaseShape = EWSType_DefaultShapeNamesType::ALL_PROPERTIES;
	// You can get the body as HTML, text or "best".
	$request->ItemShape->BodyType = EWSType_BodyTypeResponseType::HTML;

	// Add the body property.
	$body_property = new EWSType_PathToUnindexedFieldType();
	$body_property->FieldURI = 'item:Body';
	$request->ItemShape->AdditionalProperties = new EWSType_NonEmptyArrayOfPathsToElementType();
	$request->ItemShape->AdditionalProperties->FieldURI = array($body_property);

	$request->ItemIds = new EWSType_NonEmptyArrayOfBaseItemIdsType();
	$request->ItemIds->ItemId = array();

	// Add the message to the request.
	$message_item = new EWSType_ItemIdType();
	$message_item->Id = $message_id;
	$request->ItemIds->ItemId[] = $message_item;

	$response = $ews->GetItem($request);
	
	return ($response);
}

include 'php-ews-master/EWS_Exception.php';




$ews = new ExchangeWebServices($server, $username, $password, $version);

$request = new EWSType_FindItemType();
$request->ItemShape = new EWSType_ItemResponseShapeType();
$request->ItemShape->BaseShape = EWSType_DefaultShapeNamesType::ALL_PROPERTIES;

$request->Traversal = EWSType_ItemQueryTraversalType::SHALLOW;


//
// Build a new is greater than expression.
$request->Restriction = new EWSType_RestrictionType();
$request->Restriction->IsGreaterThan = new EWSType_IsGreaterThanType();

// Search on the contact's created date and time.
$request->Restriction->IsGreaterThan->FieldURI = new EWSType_PathToUnindexedFieldType();
$request->Restriction->IsGreaterThan->FieldURI->FieldURI = 'item:DateTimeReceived';

// We only want contacts created in the last week.
$request->Restriction->IsGreaterThan->FieldURIOrConstant = new EWSType_FieldURIOrConstantType();
$request->Restriction->IsGreaterThan->FieldURIOrConstant->Constant = new EWSType_ConstantValueType();
$request->Restriction->IsGreaterThan->FieldURIOrConstant->Constant->Value = $oDate->toString('c');
//

// Limits the number of items retrieved
$request->IndexedPageItemView = new EWSType_IndexedPageViewType();
$request->IndexedPageItemView->BasePoint = "Beginning";
$request->IndexedPageItemView->Offset = 0; // Item number you want to start at
$request->IndexedPageItemView->MaxEntriesReturned = 10; // Numer of items to return in total

$request->ParentFolderIds = new EWSType_NonEmptyArrayOfBaseFolderIdsType();
$request->ParentFolderIds->DistinguishedFolderId = new EWSType_DistinguishedFolderIdType();
$request->ParentFolderIds->DistinguishedFolderId->Id = EWSType_DistinguishedFolderIdNameType::INBOX;


$response = $ews->FindItem($request);


$i=0;

$tMail=array();
foreach($response->ResponseMessages->FindItemResponseMessage->RootFolder->Items->Message as $oExchMail){
	 
	 if(!isset($oExchMail->DateTimeReceived)){
		 break;
	 }
	 
	 //2014-07-25T06:25:48Z
	$tDate=explode('T',$oExchMail->DateTimeReceived);
	$sDate=$tDate[0];
	$sTime=substr($tDate[1],0,-1);
	
	
	$oMail=new stdclass;
	$oMail->subject=$oExchMail->Subject; 
	$oMail->sNameFrom=$oExchMail->From->Mailbox->Name; 
	$oMail->date=$sDate;
	$oMail->time=$sTime;
	$oMail->messageUId=$sDate.$sTime.(string)$oExchMail->ItemId->Id; 
	
	//detail
	$oDetailMail=detailMessage($oExchMail->ItemId->Id);
	
	$oMail->body=$oDetailMail->ResponseMessages->GetItemResponseMessage->Items->Message->Body->_;
	$oMail->from=$oDetailMail->ResponseMessages->GetItemResponseMessage->Items->Message->Sender->Mailbox->EmailAddress;
	
	$message=$oDetailMail->ResponseMessages->GetItemResponseMessage->Items->Message;
	
	$oMail->tFiles=null;
	
	if(!empty($message->Attachments->FileAttachment)) {
        // FileAttachment attribute can either be an array or instance of stdClass...
        $attachments = array();
        if(is_array($message->Attachments->FileAttachment) === FALSE ) {
            $attachments[] = $message->Attachments->FileAttachment;
        }
        else {
            $attachments = $message->Attachments->FileAttachment;
        }

		$oMail->tFiles=array();
        foreach($attachments as $attachment) {
            $request = new EWSType_GetAttachmentType();
            try{
				$request->AttachmentIds=new stdclass();
				$request->AttachmentIds->AttachmentId = $attachment->AttachmentId;
				$response = $ews->GetAttachment($request);
			}catch(Exception $e){
				
			}
            
            // Assuming response was successful ...
            $attachments = $response->ResponseMessages->GetAttachmentResponseMessage->Attachments;
            $content = base64_encode($attachments->FileAttachment->Content);
            
            $oMail->tFiles[]=array('name'=>$attachment->Name,'content'=>$content);
            
          
            
        }
    }
	
	$tMail[]=$oMail;

	
	$i++;

}



//enregistrement en base

if($tMail){
	foreach($tMail as $oMail){

		try{
			$return=$oSoap->setContent(
				$oMail->messageUId,
				$oMail->subject,
				$oMail->body,
				$oMail->from,
				$oMail->sNameFrom,
				$oMail->date,
				$oMail->time
			);
			
			
			if($oMail->tFiles){
				foreach($oMail->tFiles as $DetailtFile){
					$oSoap->addFile($oMail->messageUId,$DetailtFile['content'],$DetailtFile['name']);
				}
			}
			
		}catch(Exception $e){
			
			print_r($return);
			print_r($oSoap->__getLastResponse()) ;
			
		}
	}
	
	print count($tMail).' mails processed';
	
}else{
	print "rien a charger";exit;
}


