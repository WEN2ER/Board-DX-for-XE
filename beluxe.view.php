<?php

/**
 * @class  beluxeView
 * @author phiDel (xe.phidel@gmail.com)
 * @brief view class of the BoardDX module
 */

class beluxeView extends beluxe
{
    var $lstCfg = array();
    var $cmDoc = NULL;
    var $cmThis = NULL;
    var $oScrt = NULL;
    
    /**************************************************************/
    
    /*********** @initialization                        ***********/
    
    function init() {
        $this->oScrt = new Security();
        $this->oScrt->encodeHTML('mid');
        
        // module_srl 체크
        if (!$this->module_srl || !$this->module_info->module_srl) {
            $this->mid = Context::get('mid');
            if ($this->mid) {
                $cmModule = & getModel('module');
                $oModIfo = $cmModule->getModuleInfoByMid($this->mid);
                if ($oModIfo) {
                    ModuleModel::syncModuleToSite($oModIfo);
                    $this->module_info = $oModIfo;
                    $this->module_srl = $oModIfo->module_srl;
                    Context::set('module_info', $this->module_info);
                } 
                else return $this->stop('error');
            }
            else return $this->stop('error');
        }
        
        $oModIfo = $this->module_info;
        
        // 잘못된 방법을 막기 위한 초기화
        Context::set('module_srl', $this->module_srl);
        
        //필수 클래스 셋팅
        Context::set('oThis', new beluxeItem($this->module_srl));
        
        $navi = explode('|@|', $oModIfo->default_type_option);
        $oModIfo->default_sort_index = $navi[0] ? $navi[0] : 'list_order';
        $oModIfo->default_order_type = $navi[1] ? $navi[1] : 'asc';
        $oModIfo->default_list_count = $navi[2] ? $navi[2] : 20;
        $oModIfo->default_page_count = $navi[3] ? $navi[3] : 10;
        $oModIfo->default_clist_count = $navi[4] ? $navi[4] : 50;
        Context::set('module_info', $oModIfo);
        
        // 상담 기능 체크. 현재 게시판의 관리자이면 상담기능을 off시킴, 현재 사용자가 비로그인 사용자라면 글쓰기/댓글쓰기/목록보기/글보기 권한을 제거
        if ($oModIfo->consultation == 'Y' && !Context::get('is_logged')) {
            $this->grant->list = $this->grant->write_document = $this->grant->write_comment = $this->grant->view = FALSE;
        }
        
        // 스킨 경로를 미리 template_path 라는 변수로 설정
        if (!$oModIfo->skin || $oModIfo->skin == '/USE_DEFAULT/') $oModIfo->skin = 'default';
        $tpl_path = sprintf('%sskins/%s/', $this->module_path, $oModIfo->skin);
        if (!is_dir($tpl_path)) return $this->stop('msg_skin_does_not_exist');
        
        // 팝업,프레임 레이아웃 설정
        if (Context::get('is_poped') || Context::get('is_modal')) {
            $this->setLayoutPath('./common/tpl');
            if (Context::get('is_poped')) $this->setLayoutFile('popup_layout');
            else $this->setLayoutFile('default_layout');
        }
        
        $this->setTemplatePath($tpl_path);         
        // 스킨 언어팩은 따로 읽기
        Context::loadLang($tpl_path);       
        // 공통 자바 추가
        Context::addJsFile($this->module_path . 'tpl/js/module.' . ((!__DEBUG__) ? 'min.' : '') . 'js');
        
        // 검색 로봇 제한
        if ($oModIfo->robots_meta_option) {
            Context::addHtmlHeader('<meta name="robots" content="' . $oModIfo->robots_meta_option . '" />');
        }
    }
    
    /**************************************************************/
    
    /*********** @private function                      ***********/
    
    function _setXeValidatorMessage($a_err, $a_msg, $a_type = 'info') 
    {
        $xemsg = Context::get('XE_VALIDATOR_MESSAGE');
        Context::set('XE_VALIDATOR_MESSAGE_TYPE', $a_type);
        Context::set('XE_VALIDATOR_MESSAGE', ($xemsg ? $xemsg . '<br /><br />' : '') . $a_msg);
        Context::set('XE_VALIDATOR_ERROR', $a_err);
    }
    
    /* @brief set common info */
    function _setBeluxeCommonInfo() {
        
        // 한번만 부르려고 전역 셋팅
        $this->cmDoc = & getModel('document');
        $this->cmThis = & getModel(__XEFM_NAME__);
        
        // 대상 항목을 구함
        $this->lstCfg = $this->cmThis->getColumnInfo($this->module_srl);
        Context::set('column_info', $this->lstCfg);
        
        $this->lstCfg['temp']->iscate = $this->lstCfg['category_srl'] && $this->lstCfg['category_srl']->display == 'Y';
        
        // 카테고리를 사용안하면 제거
        if (!$this->lstCfg['temp']->iscate) Context::set('category_srl', '');
    }
    
    /* @brief get content list */
    function _setBeluxeContentList(&$aDoc) {
        $load_extvars = TRUE;
        $this->oScrt->encodeHTML('category_srl', 'sort_index', 'page', 'list_count', 'page_count', 'search_target', 'search_keyword');
        $args = Context::gets('category_srl', 'sort_index', 'page', 'list_count', 'page_count', 'search_target', 'search_keyword');
        
        $oModIfo = $this->module_info;
        $args->module_srl = $this->module_srl;
        
        $is_doc = $aDoc && $aDoc->isExists();
        $is_btm_lst = $oModIfo->document_bottom_list ? $oModIfo->document_bottom_list : 'Y';
        $is_btm_skp = !$is_doc || $oModIfo->document_bottom_except_notice != 'N';
        
        // 상담 기능시 현재 로그인 사용자 글만 나타나도록 변경
        if ($oModIfo->consultation == 'Y' && !$this->grant->manager) {
            $oLogIfo = Context::get('logged_info');
            $args->member_srl = $oLogIfo->member_srl;
        }
        
        // 분류에 navigation 이 있으면 설정
        if ($args->category_srl) {
            $ct_navi = $this->cmThis->getCategoryList($this->module_srl, $args->category_srl);
            if (count($ct_navi->navigation)) {
                $ct_navi = $ct_navi->navigation;
                if (!$args->sort_index) $args->sort_index = $ct_navi->sort_index;
                if (!$args->order_type) $args->order_type = $ct_navi->order_type;
                if (!$args->list_count) $args->list_count = (int)$ct_navi->list_count;
                if (!$args->page_count) $args->page_count = (int)$ct_navi->page_count;
            }
        }
        
        // 지정된 정렬값이 없다면  기본 설정 값을 이용, 확장변수면 eid 값 넣기
        if (!$this->lstCfg[$args->sort_index]) {
            $args->sort_index = $oModIfo->default_sort_index ? $oModIfo->default_sort_index : 'list_order';
        } 
        else {
            if ($this->lstCfg[$args->sort_index]->idx > 0) $args->sort_index = $this->lstCfg[$args->sort_index]->eid;
        }
        if (!in_array($args->order_type, array('asc', 'desc'))) {
            $args->order_type = $oModIfo->default_order_type ? $oModIfo->default_order_type : 'asc';
        }
        
        // 잘못된 정렬,검색 타겟 재설정
        switch ($args->sort_index) {
            case 'no':
                $args->sort_index = 'list_order';
                break;

            case 'document_srl':
                $args->sort_index = 'list_order';
                break;

            case 'last_update':
                $args->sort_index = 'update_order';
                break;

            case 'custom_status':
                $args->sort_index = 'is_notice';
                break;
        }
        
        //목록수, 페이지수 설정
        if (!$args->list_count) $args->list_count = $oModIfo->default_list_count ? $oModIfo->default_list_count : '20';
        if (!$args->page_count) $args->page_count = $oModIfo->default_page_count ? $oModIfo->default_page_count : '10';
        
        // 목록 보기 권한이 없을 경우 목록없음, 문서가 있고 하단 목록 타입이 없으면 목록없음
        if (!$this->grant->list || ($is_doc && $is_btm_lst == 'N')) {
            Context::set('document_list', array());
            Context::set('total_count', 0);
            Context::set('total_page', 1);
            Context::set('page_navigation', new PageHandler(0, 0, 1, 10));
            Context::set('page', 1);
        } 
        else {
            
            $is_btm_cnt = (int)($oModIfo->document_bottom_list_count ? $oModIfo->document_bottom_list_count : $args->list_count);
            
            $ori_page = $nvi_page = 0;
            
            if ($is_doc) {
                
                // 목록 수와 네비 수가 다르면 목록 페이지 값 구함
                if ($args->list_count != $is_btm_cnt) {
                    $ori_page = $this->cmDoc->getDocumentPage($aDoc, $args);
                    
                    // 네비 목록을 구해야 하니 다르면 네비 수로 다시 설정
                    $args->list_count = $is_btm_cnt;
                }
                
                // 네비 페이지가 없으면 구하고 설정
                $nvi_page = (int)Context::get('npage');
                if (!$nvi_page) $nvi_page = $this->cmDoc->getDocumentPage($aDoc, $args);
                $args->page = $nvi_page;
            }
            
            $except_notice = $args->search_keyword ? FALSE : $is_btm_skp;
            $out = $this->cmDoc->getDocumentList($args, $except_notice, $load_extvars);
            
            Context::set('document_list', $out->data);
            Context::set('page_navigation', $out->page_navigation);
            Context::set('total_count', $out->total_count);
            Context::set('total_page', $out->total_page);
            Context::set('npage', $is_doc ? ($nvi_page ? $nvi_page : $out->page) : '');
            Context::set('page', $ori_page ? $ori_page : $out->page);
        }
        
        // 다른 모듈이나 에드온에서 사용하기 위해 검색 옵션 저장
        Context::set('beluxe_doc_list_sort_keys', $args);
    }
    
    /* @brief get content item */
    function _setBeluxeContentView($a_iswrite = FALSE) {
        $oModIfo = $this->module_info;
        $this->oScrt->encodeHTML('document_srl', 'category_srl');
        $doc_srl = Context::get('document_srl');
        
        // 목록 대신 최근 문서부터 보여야 할때 사용하는 옵션
        $is_lat_doc = $oModIfo->use_latest_content_view && ($oModIfo->use_latest_content_view == $oModIfo->default_type);
        if (!$doc_srl && !$a_iswrite && $is_lat_doc && !Context::get('list_count')) {
            $doc_srl = $this->cmThis->getLatestDocumentSrl($oModIfo->module_srl);
            if ($doc_srl) Context::set('document_srl', $doc_srl);
        }
        
        if ($doc_srl || $a_iswrite) {
            
            // TODO ruleset 확장 변수 체크 코어에서 고칠때까지 임시 조치
            if ($a_iswrite) {
                $this->cmThis->_addDocumentJsFilter($oModIfo->module_srl);
            }
            
            $oLogIfo = Context::get('logged_info');
            $mbr_srl = $oLogIfo->member_srl;
            
            $load_extvars = TRUE;
            
            // 해당 콘텐츠 뷰 셋팅
            $out = $this->cmDoc->getDocument((int)$doc_srl, $this->grant->manager, $load_extvars);
            
            if ($out->isExists()) {
                $is_grant = $out->isGranted();
                $is_secret = $out->isSecret();
                
                if (!$out->isNotice()) {
                    
                    // 글 보기 권한을 체크
                    $is_empty = !$this->grant->{$a_iswrite ? 'write_document' : 'view'} && !$is_grant;
                    
                    // 상담기능이 사용되면 사용자의 글인지 체크
                    if (!$is_empty && $oModIfo->consultation == 'Y') $is_empty = !$is_grant;
                    
                    // 수정시 비회원 글 권한 체크
                    if (!$is_empty && $a_iswrite && !$out->get('member_srl')) $is_empty = !$is_grant;
                    
                    // 블라인드 기능이 사용되면 블라인드 체크
                    if (!$is_empty && !$this->grant->manager && $oModIfo->use_blind == 'Y') $is_empty = $this->cmThis->isBlind($doc_srl);
                } 
                else {
                    
                    // 공지는 누구나 볼 수 있게 함
                    $this->grant->view = TRUE;
                    
                    // 수정시 권한 체크
                    if ($a_iswrite) $is_empty = !$is_grant;
                }
                
                // 권한이 없으면 빈문서
                if ($is_empty) {
                    $b_title = Context::getLang('msg_not_permitted');
                    $out = $this->cmDoc->getDocument(0, FALSE, FALSE);
                    $this->_setXeValidatorMessage(-1380, $b_title, 'error');
                } 
                else {
                    
                    // 조회수 증가
                    if (!$is_secret || $is_grant) $out->updateReadedCount();
                    
                    $b_title = $out->getTitleText();
                    
                    // 넘어온 분류와 문서 분류가 다를 경우 바꿈
                    $temp = $this->lstCfg['temp'];
                    $temp->olcate = Context::get('category_srl');
                    $temp->dccate = $out->get('category_srl');
                    
                    //공지는 제외
                    $temp->chcate = $oModIfo->category_trace != 'N' && (!$out->isNotice() || $oModIfo->notice_category == 'Y');
                    $temp->chcate = $temp->chcate && $temp->iscate && $temp->dccate != $temp->olcate;
                    if ($temp->chcate) {
                        $args->category_srl = $temp->dccate;
                        Context::set('category_srl', $args->category_srl);
                    }
                }
            }
            
            // 브라우저 타이틀에 글의 제목을 추가
            Context::addBrowserTitle($b_title);
            Context::set('oDocument', $out);
        }

        return $out;
    }
    
    /* @brief get comment item */
    function _setBeluxeCommentView($a_iswrite = FALSE) {
        $this->oScrt->encodeHTML('document_srl', 'comment_srl', 'parent_srl');
        
        // 목록 구현에 필요한 변수들을 가져온다
        $doc_srl = Context::get('document_srl');
        $cmt_srl = Context::get('comment_srl');
        $par_srl = Context::get('parent_srl');
        
        // 해당 댓글를 찾아본다
        $cmComment = & getModel('comment');
        $out = $cmComment->getComment((int)$cmt_srl, $this->grant->manager);
        
        if ($out->isExists()) {
            
            // 글 보기 권한을 체크해서 권한이 없으면 빈문서
            $is_empty = !$this->grant->{$a_iswrite ? 'write_comment' : 'view'} && !$out->isGranted();
            
            // 수정시 비회원 글이고  권한이 없으면 빈문서
            if ($a_iswrite && !$is_empty && !$out->get('member_srl')) $is_empty = !$out->isGranted();
        }
        
        // 문서 번호가 없거나 권한이 없으면 빈문서
        if ($is_empty || !$doc_srl) {
            $out = $cmComment->getComment(0, FALSE);
            if ($is_empty) {
                $msg = Context::getLang('msg_not_permitted');
                $this->_setXeValidatorMessage(-1380, $b_title, 'error');
            }
        }
        
        $par_srl = $par_srl ? $par_srl : $out->get('parent_srl');
        
        // 필요한 정보들 세팅
        Context::set('document_srl', $doc_srl);
        Context::set('oComment', $out);
        Context::set('oSourceComment', $cmComment->getComment((int)$par_srl));
    }
    
    /**************************************************************/
    
    /*********** @public function                       ***********/
    
    function dispBoardHistory() {
        $this->_setBeluxeCommonInfo();
        
        $err = 'msg_invalid_request';
        $this->oScrt->encodeHTML('history_srl');
        $his_srl = (int)Context::get('history_srl');
        
        if ($his_srl) {
            $his = $this->cmDoc->getHistory($his_srl);
            if ($his && $his->document_srl) {
                
                // 원본 문서의 권한 체크
                Context::set('document_srl', $his->document_srl);
                $doc = $this->_setBeluxeContentView();
                $err = '';
                $is_grant = $doc->isGranted();
                $is_secret = $doc->isSecret();
                
                // 권한 체크
                $err = Context::get('XE_VALIDATOR_ERROR');
                if (!$is_grant && ($is_secret || $err == '-1380')) {
                    $msg = Context::getLang('msg_not_permitted');
                    $his->content = $msg;
                }
            }
        }
        
        if ($err) {
            $msg = Context::getLang($err);
            $this->_setXeValidatorMessage(-1380, $b_title, 'error');
        }
        
        Context::set('history_document', $his);
        $this->setTemplateFile('history');
    }
    
    function dispBoardTagList() {
        if ($this->grant->list) {
            $cmTag = & getModel('tag');
            
            $obj->mid = $this->mid;
            $obj->list_count = Context::get('list_count');
            $obj->list_count = $obj->list_count ? $obj->list_count : 10000;
            $out = $cmTag->getTagList($obj);
            
            // automatically order
            if (count($out->data)) {
                $numbers = array_keys($out->data);
                shuffle($numbers);
                
                if (count($out->data)) {
                    foreach ($numbers as $k => $v) {
                        $tag_list[] = $out->data[$v];
                    }
                }
            }
            
            Context::set('tag_list', $tag_list);
            $this->oScrt->encodeHTML('tag_list.');
        }
        
        $this->setTemplateFile('doctags');
    }
    
    function dispBoardContent() {
        $this->_setBeluxeCommonInfo();
        $doc = $this->_setBeluxeContentView();
        $this->_setBeluxeContentList($doc);
        $this->setTemplateFile('index');
    }
    
    function dispBoardWrite() {
        $this->_setBeluxeCommonInfo();
        $this->_setBeluxeContentView(TRUE);
        $this->setTemplateFile('write');
    }
    
    function dispBoardWriteComment() {
        $this->_setBeluxeCommonInfo();
        $this->_setBeluxeCommentView(TRUE);
        $this->setTemplateFile('write');
    }
    
    function dispBoardDelete() {
        $this->dispBoardWrite();
        $this->setTemplateFile('delete');
    }
    
    function dispBoardDeleteComment() {
        $this->dispBoardWriteComment();
        $this->setTemplateFile('delete');
    }
    
    /**************************************************************/
}

/* End of file beluxe.view.php */

/* Location: ./modules/beluxe/beluxe.view.php */
