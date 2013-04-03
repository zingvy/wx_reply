<?php
define ('TOKEN', 'yourweixintoken');

class WeixinController{
    private static function _arrayToXml($data, $rootNodeName = 'xml', $xml = null) {
        if ($xml == null) {
            $xml = simplexml_load_string("<?xml version='1.0' encoding='UTF-8'?><$rootNodeName />");
        }
        foreach($data as $key => $value) {
            if (!is_numeric($value) && !is_array($value)) {
                $value = htmlentities($value, ENT_QUOTES);
                $value = "<![CDATA[$value]]>";
            }
            
            if (is_numeric($key)) {
                $key = 'item';
            }

            if (is_array($value)) {
                $node = $xml->addChild($key);
                self::_arrayToXml($value, $rootNodeName, $node);
            } else {
                $xml->addChild($key, $value);
            }
        }

        return  html_entity_decode($xml->asXML());
    } 

	private static function _genReplyXml($toUser, $fromUser, $createTime, $replyData) {
	    $replyData['ToUserName'] = $toUser;
	    $replyData['FromUserName'] = $fromUser;
	    $replyData['CreateTime'] = $createTime;

        return self::_arrayToXml($replyData);
    }

    private static function _decodeSingleArticle($data) {
        return array(
            'Title' => $data['title'],
            'Description' => $data['introduce'],
            'PicUrl' => $data['cover_url'],
            'Url' => "http://wap.meilishuo.com/wapWeixin/show_reply?reply_id={$data['reply_id']}",
        ); 
    }

	private static function _decodeAutoReplies($autoReplies) {
		$replyData = array();
        foreach ($autoReplies as $autoReply) {
            if ($autoReply['type'] == 1) {
                $replyData[] = array(
                    'MsgType' => 'text',
                    'Content' => $autoReply['msg'],
                    'FuncFlag' => 0,
                );
            } else if ($autoReply['type'] == 3) {
                $replyData[] = array(
                    'MsgType' => 'news',
                    'FuncFlag' => 0,
                    'Content' => '',
                    'ArticleCount' => 1,
                    'Articles' => array(self::_decodeSingleArticle($autoReply)),
                );
            } else if ($autoReply['type'] == 4) {
                $articles = array();
                foreach ($autoReply['item'] as $item) {
                    $articles[] = self::_decodeSingleArticle($item);
                }
                $replyData[] = array(
                    'MsgType' => 'news',
                    'FuncFlag' => 0,
                    'Content' => '',
                    'ArticleCount' => count($articles),
                    'Articles' => $articles,
                );
            }
        }
		!empty($replyData) && $replyData = $replyData[0];
		return $replyData;
	}
	
	private static function _decodeRobotReplies($robotReplies) {
		$replyData = $robotReplies;
		!empty($replyData) && $replyData = $replyData[0];
		return $replyData;
	}
	
	private static function _getReplyDataByContent($content) {
		$content = trim($content);
		$autoReplies = admin_weixinAutoReplyModel::getInstance()->getReplyByContent($content);
		if (!empty($autoReplies)) {
			$replyData = self::_decodeAutoReplies($autoReplies);
			return $replyData;
		}
		$robotReplies = api_weixinRobotReplyModel::getInstance()->getReplyByContent($content);
		if (!empty($robotReplies)) {
			$replyData = self::_decodeRobotReplies($robotReplies);
			return $replyData;
		}
		$defaultReplies = admin_weixinAutoReplyModel::getInstance()->getDefaultReply();
		$replyData = self::_decodeAutoReplies($defaultReplies);
		return $replyData;
	}
	
	public function doAction() {
		parent::doAction();
	}
	
	public function do_imeili() {
		$this->do_autoreply();
	}

    public function do_test() {
        $replyData = self::_getReplyDataByContent('时尚不神秘');
		if (!empty($replyData)) {
			echo self::_genReplyXml('test_a', 'test_b', time(), $replyData);
		}
    }
	
	public function do_autoreply() {
		$signature = $_GET["signature"];
		$timestamp = $_GET["timestamp"];
		$nonce = $_GET["nonce"];
		$echostr = $_GET["echostr"];
		
		if (isset($echostr)) {
			if ($this->_checkSignature($signature, $timestamp, $nonce)) {
				echo $echostr;
			} else {
				echo "HEllO WORLD";
			}
			exit;
		}

		$postData = $this->_parsePostData($GLOBALS["HTTP_RAW_POST_DATA"]);
		switch ($postData['MsgType']) {
			case 'text':
				$this->_handleText($postData);
				break;
            case 'event':
                $this->_handleEvent($postData);
                break;
            default:
                break;
        }

        $log = "[{$postData['FromUserName']}]\t[{$postData['ToUserName']}]\t[{$postData['CreateTime']}]\t[{$postData['MsgType']}]\t[{$postData['Content']}]\t[{$postData['PicUrl']}]\t[{$postData['Url']}]\t[{$postData['Event']}]\t[{$postData['EventKey']}]";
        $logHandle = new zx_log("weixin_event_log", "normal");
        $logHandle->w_log($log);
	}
	
	private function _handleText($postData) {
		$replyData = self::_getReplyDataByContent($postData['Content']);
		if (!empty($replyData)) {
			echo self::_genReplyXml($postData['FromUserName'], $postData['ToUserName'], time(), $replyData);
		}
	}

    private function _handleEvent($postData) {
        switch ($postData['Event']) {
            case 'subscribe':
                $this->_replyNewFollower($postData);        
                break; 
            default:
                break;
        } 
    }

    private function _replyNewFollower($postData) {
        $newFollowerReplies = admin_weixinAutoReplyModel::getInstance()->getNewFollowerReply();
        $replyData = self::_decodeAutoReplies($newFollowerReplies);
        if (!empty($replyData)) {
            echo self::_genReplyXml($postData['FromUserName'], $postData['ToUserName'], time(), $replyData);
        }
    }
	
	private function _checkSignature($signature, $timestamp, $nonce) {
		$tmpArr = array(TOKEN, $timestamp, $nonce);
		sort($tmpArr);
		$tmpStr = implode($tmpArr);
		$tmpStr = sha1($tmpStr);

		if ($tmpStr == $signature) {
			return true;
		} else {
			return false;
		}
	}
	
	private function _parsePostData($postData) {
		$postObj = simplexml_load_string($postData, 'SimpleXMLElement', LIBXML_NOCDATA);
		return (array)$postObj;
	}
}
