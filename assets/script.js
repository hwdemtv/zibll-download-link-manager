(function($) {
    'use strict';
    
    var currentLinks = [];
    var filteredLinks = [];
    var currentPage = 1;
    var perPage = 20;
    var currentFilter = {
        domain: '',
        text: '',
        time: '',
        dateFrom: '',
        dateTo: ''
    };
    
    $(document).ready(function() {
        // 加载日志
        loadLogs();
        
        // 刷新日志
        $('#btn-refresh-logs').on('click', function() {
            loadLogs();
        });
        
        // 清空日志
        $('#btn-clear-logs').on('click', function() {
            if (!confirm('确定要清空所有日志吗？')) {
                return;
            }
            
            $.ajax({
                url: zibllDlm.ajax_url,
                type: 'POST',
                data: {
                    action: 'zibll_dlm_clear_logs',
                    nonce: zibllDlm.nonce
                },
                success: function(response) {
                    if (response.success) {
                        showMessage('success', '日志已清空');
                        loadLogs();
                    } else {
                        showMessage('error', response.data.message || zibllDlm.strings.error);
                    }
                }
            });
        });

        // 搜索链接
        $('#btn-search').on('click', function() {
            searchLinks(1);
        });
        
        // 每页条数变更
        $('#per-page-select').on('change', function() {
            perPage = parseInt($(this).val());
            // 如果已有搜索结果，重新搜索当前页（或重置为第一页）
            if (currentLinks.length > 0 || $('.zibll-dlm-link-item').length > 0) {
                searchLinks(1);
            }
        });

        // ...
        
        // 回车搜索
        $('#filter-title, #filter-search, #filter-domain').on('keypress', function(e) {
            if (e.which === 13) {
                searchLinks(1);
            }
        });
        
        // 分页点击
        $(document).on('click', '.zibll-dlm-page-btn', function() {
            var page = $(this).data('page');
            searchLinks(page);
        });

        // ...
        
        // 重置
        $('#btn-reset').on('click', function() {
            $('#filter-title').val('');
            $('#filter-search').val('');
            $('#filter-domain').val('');
            $('#filter-use-regex').prop('checked', false);
            $('#links-container').html('<p class="description">请先使用搜索筛选功能查找链接</p>');
            $('#link-count').text('');
            currentLinks = [];
            filteredLinks = [];
            currentFilter = { domain: '', text: '' };
            resetListFilter();
        });
        
        // 列表筛选 - 快捷标签
        $(document).off('click', '.zibll-dlm-filter-tag').on('click', '.zibll-dlm-filter-tag', function() {
            var filterType = $(this).data('filter');
            var filterValue = $(this).data('value');
            
            if (filterType === 'clear') {
                currentFilter = { domain: '', text: '', time: '', dateFrom: '', dateTo: '' };
                $('#list-filter-text').val('');
                $('#filter-date-from').val('');
                $('#filter-date-to').val('');
                $('.zibll-dlm-filter-tag').removeClass('active');
            } else if (filterType === 'domain') {
                if (currentFilter.domain === filterValue) {
                    currentFilter.domain = '';
                    $(this).removeClass('active');
                } else {
                    currentFilter.domain = filterValue;
                    $('.zibll-dlm-filter-tag[data-filter="domain"]').removeClass('active');
                    $(this).addClass('active');
                }
                // 清除时间筛选
                currentFilter.time = '';
                currentFilter.dateFrom = '';
                currentFilter.dateTo = '';
                $('#filter-date-from').val('');
                $('#filter-date-to').val('');
                $('.zibll-dlm-filter-tag[data-filter="time"]').removeClass('active');
            } else if (filterType === 'time') {
                if (currentFilter.time === filterValue) {
                    currentFilter.time = '';
                    $(this).removeClass('active');
                } else {
                    currentFilter.time = filterValue;
                    currentFilter.dateFrom = '';
                    currentFilter.dateTo = '';
                    $('#filter-date-from').val('');
                    $('#filter-date-to').val('');
                    $('.zibll-dlm-filter-tag[data-filter="time"]').removeClass('active');
                    $(this).addClass('active');
                }
            }
            
            applyListFilter();
        });
        
        // 日期范围筛选
        $('#btn-filter-date').on('click', function() {
            var dateFrom = $('#filter-date-from').val();
            var dateTo = $('#filter-date-to').val();
            
            if (!dateFrom && !dateTo) {
                showMessage('error', '请至少选择一个日期');
                return;
            }
            
            currentFilter.time = '';
            currentFilter.dateFrom = dateFrom;
            currentFilter.dateTo = dateTo;
            
            // 清除时间快捷标签
            $('.zibll-dlm-filter-tag[data-filter="time"]').removeClass('active');
            
            applyListFilter();
        });
        
        // 列表筛选 - 文本输入
        $('#btn-list-filter').on('click', function() {
            currentFilter.text = $('#list-filter-text').val().trim();
            applyListFilter();
        });
        
        // 列表筛选 - 回车搜索
        $('#list-filter-text').on('keypress', function(e) {
            if (e.which === 13) {
                $('#btn-list-filter').click();
            }
        });
        
        // 列表筛选 - 重置
        $('#btn-list-filter-reset').on('click', function() {
            currentFilter = { domain: '', text: '' };
            $('#list-filter-text').val('');
            $('.zibll-dlm-filter-tag').removeClass('active');
            applyListFilter();
        });
        
        // 导出链接
        $('#btn-export').on('click', function() {
            exportLinks();
        });
        
        // 导入链接
        $('#btn-import').on('click', function() {
            importLinks();
        });
    });
    
    /**
     * 搜索链接
     */
    function searchLinks(page) {
        page = page || 1;
        currentPage = page;
        showLoading();
        
        $.ajax({
            url: zibllDlm.ajax_url,
            type: 'POST',
            data: {
                action: 'zibll_dlm_get_links',
                nonce: zibllDlm.nonce,
                title: $('#filter-title').val(),
                search: $('#filter-search').val(),
                domain: $('#filter-domain').val(),
                use_regex: $('#filter-use-regex').is(':checked'),
                page: page,
                per_page: perPage
            },
            success: function(response) {
                hideLoading();
                
                if (response.success) {
                    currentLinks = response.data.links;
                    filteredLinks = currentLinks;
                    
                    // 确保每页条数选择框的值正确
                    if (response.data.per_page) {
                        perPage = parseInt(response.data.per_page);
                        $('#per-page-select').val(perPage);
                    }
                    
                    // 显示分页
                    renderPagination(response.data.total_pages, response.data.current_page, response.data.total_posts);
                    
                    // 先显示所有链接，然后应用筛选
                    displayLinks(currentLinks);
                    // 如果有筛选条件，应用筛选
                    if (currentFilter.domain || currentFilter.text) {
                        applyListFilter();
                    }
                    
                    var countMsg = '找到 ' + response.data.count + ' 个链接';
                    if (response.data.total_posts > 0) {
                        countMsg += ' (共 ' + response.data.total_posts + ' 篇文章)';
                    }
                    showMessage('success', countMsg);
                } else {
                    showMessage('error', response.data.message || zibllDlm.strings.error);
                }
            },
            error: function() {
                hideLoading();
                showMessage('error', zibllDlm.strings.error);
            }
        });
    }
    
    /**
     * 渲染分页
     */
    function renderPagination(totalPages, currentPage, totalPosts) {
        var $pagination = $('#zibll-dlm-pagination');
        if (!totalPages || totalPages <= 1) {
            $pagination.hide();
            return;
        }
        
        var html = '';
        html += '<div class="tablenav-pages">';
        html += '<span class="displaying-num">' + totalPosts + ' 篇文章</span>';
        html += '<span class="pagination-links">';
        
        if (currentPage > 1) {
            html += '<button type="button" class="button zibll-dlm-page-btn" data-page="1">« 首页</button> ';
            html += '<button type="button" class="button zibll-dlm-page-btn" data-page="' + (currentPage - 1) + '">‹ 上一页</button> ';
        } else {
            html += '<span class="tablenav-pages-navspan button disabled">« 首页</span> ';
            html += '<span class="tablenav-pages-navspan button disabled">‹ 上一页</span> ';
        }
        
        html += '<span class="paging-input">';
        html += '<span class="current-page">第 ' + currentPage + ' 页，共 ' + totalPages + ' 页</span>';
        html += '</span> ';
        
        if (currentPage < totalPages) {
            html += '<button type="button" class="button zibll-dlm-page-btn" data-page="' + (currentPage + 1) + '">下一页 ›</button> ';
            html += '<button type="button" class="button zibll-dlm-page-btn" data-page="' + totalPages + '">尾页 »</button>';
        } else {
            html += '<span class="tablenav-pages-navspan button disabled">下一页 ›</span> ';
            html += '<span class="tablenav-pages-navspan button disabled">尾页 »</span>';
        }
        
        html += '</span></div>';
        
        $pagination.html(html).show();
    }
    
    /**
     * 应用列表筛选
     */
    function applyListFilter() {
        if (currentLinks.length === 0) {
            filteredLinks = [];
            return;
        }
        
        // 如果链接还没有显示，等待一下再筛选
        if ($('.zibll-dlm-link-item').length === 0) {
            setTimeout(function() {
                applyListFilter();
            }, 100);
            return;
        }
        
        var visibleCount = 0;
        var hasFilter = currentFilter.domain || currentFilter.text || currentFilter.time || currentFilter.dateFrom || currentFilter.dateTo;
        
        // 如果没有筛选条件，显示所有链接
        if (!hasFilter) {
            $('.zibll-dlm-link-item').show();
            visibleCount = currentLinks.length;
            $('#link-count').text('(' + currentLinks.length + ')');
            $('#links-container .zibll-dlm-empty').remove();
            updateSelection();
            return;
        }
        
        // 使用显示/隐藏来筛选
        $('.zibll-dlm-link-item').each(function() {
            var $item = $(this);
            var domain = $item.data('domain') || '';
            var linkText = $item.find('.zibll-dlm-link-url-display').text();
            var titleText = $item.find('.zibll-dlm-link-title').text();
            var show = true;
            
            // 域名筛选 - 改进匹配逻辑
            if (currentFilter.domain) {
                var filterDomain = currentFilter.domain.toLowerCase();
                
                // 优先使用data-domain属性
                if (domain) {
                    var itemDomain = domain.toLowerCase();
                    if (itemDomain.indexOf(filterDomain) === -1) {
                        show = false;
                    }
                } else if (linkText) {
                    // 如果data-domain没有值，从链接文本中提取并检查
                    var extractedDomain = extractDomain(linkText);
                    if (extractedDomain) {
                        var itemDomain = extractedDomain.toLowerCase();
                        if (itemDomain.indexOf(filterDomain) === -1) {
                            show = false;
                        }
                    } else {
                        // 如果无法提取域名，检查链接文本中是否包含筛选域名
                        if (linkText.toLowerCase().indexOf(filterDomain) === -1) {
                            show = false;
                        }
                    }
                } else {
                    show = false;
                }
            }
            
            // 文本筛选
            if (show && currentFilter.text) {
                var searchText = currentFilter.text.toLowerCase();
                var linkTextLower = linkText.toLowerCase();
                var titleTextLower = titleText.toLowerCase();
                
                if (linkTextLower.indexOf(searchText) === -1 && 
                    titleTextLower.indexOf(searchText) === -1) {
                    show = false;
                }
            }
            
            // 时间筛选
            if (show && (currentFilter.time || currentFilter.dateFrom || currentFilter.dateTo)) {
                var postDate = $item.data('post-date');
                if (!postDate) {
                    show = false;
                } else {
                    try {
                        var postDateObj = new Date(postDate);
                        if (isNaN(postDateObj.getTime())) {
                            show = false;
                        } else {
                            var now = new Date();
                            var today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
                            var yesterday = new Date(today);
                            yesterday.setDate(yesterday.getDate() - 1);
                            var weekAgo = new Date(today);
                            weekAgo.setDate(weekAgo.getDate() - 7);
                            var monthAgo = new Date(today);
                            monthAgo.setDate(monthAgo.getDate() - 30);
                            
                            var match = false;
                            
                            // 快捷时间筛选
                            if (currentFilter.time === 'today') {
                                var postDateOnly = new Date(postDateObj.getFullYear(), postDateObj.getMonth(), postDateObj.getDate());
                                match = postDateOnly.getTime() === today.getTime();
                            } else if (currentFilter.time === 'yesterday') {
                                var postDateOnly = new Date(postDateObj.getFullYear(), postDateObj.getMonth(), postDateObj.getDate());
                                match = postDateOnly.getTime() === yesterday.getTime();
                            } else if (currentFilter.time === 'week') {
                                match = postDateObj >= weekAgo;
                            } else if (currentFilter.time === 'month') {
                                match = postDateObj >= monthAgo;
                            }
                            
                            // 日期范围筛选
                            if (currentFilter.dateFrom || currentFilter.dateTo) {
                                var fromDate = currentFilter.dateFrom ? new Date(currentFilter.dateFrom + 'T00:00:00') : null;
                                var toDate = currentFilter.dateTo ? new Date(currentFilter.dateTo + 'T23:59:59') : null;
                                var postDateOnly = new Date(postDateObj.getFullYear(), postDateObj.getMonth(), postDateObj.getDate());
                                
                                if (fromDate && toDate) {
                                    var fromDateOnly = new Date(fromDate.getFullYear(), fromDate.getMonth(), fromDate.getDate());
                                    var toDateOnly = new Date(toDate.getFullYear(), toDate.getMonth(), toDate.getDate());
                                    match = postDateOnly >= fromDateOnly && postDateOnly <= toDateOnly;
                                } else if (fromDate) {
                                    var fromDateOnly = new Date(fromDate.getFullYear(), fromDate.getMonth(), fromDate.getDate());
                                    match = postDateOnly >= fromDateOnly;
                                } else if (toDate) {
                                    var toDateOnly = new Date(toDate.getFullYear(), toDate.getMonth(), toDate.getDate());
                                    match = postDateOnly <= toDateOnly;
                                }
                            }
                            
                            if (!match) {
                                show = false;
                            }
                        }
                    } catch (e) {
                        show = false;
                    }
                }
            }
            
            if (show) {
                $item.show();
                visibleCount++;
            } else {
                $item.hide();
            }
        });
        
        // 更新计数显示
        var filterInfo = '';
        if (hasFilter) {
            filterInfo = ' (筛选后: ' + visibleCount + ' / ' + currentLinks.length + ')';
        }
        $('#link-count').text('(' + currentLinks.length + ')' + filterInfo);
        
        // 如果没有可见项，显示提示
        $('#links-container .zibll-dlm-empty').remove();
        if (visibleCount === 0 && hasFilter) {
            $('#links-container').append('<div class="zibll-dlm-empty" style="margin-top: 20px;">未找到符合条件的链接，请调整筛选条件</div>');
        }
        
        // 更新选择状态
        updateSelection();
    }
    
    /**
     * 重置列表筛选
     */
    function resetListFilter() {
        $('#list-filter-text').val('');
        $('#filter-date-from').val('');
        $('#filter-date-to').val('');
        $('.zibll-dlm-filter-tag').removeClass('active');
        currentFilter = { domain: '', text: '', time: '', dateFrom: '', dateTo: '' };
    }
    
    /**
     * 显示链接列表
     */
    function displayLinks(links) {
        var container = $('#links-container');
        
        if (links.length === 0) {
            if (currentLinks.length > 0) {
                container.html('<div class="zibll-dlm-empty">未找到符合条件的链接，请调整筛选条件</div>');
            } else {
                container.html('<div class="zibll-dlm-empty">' + zibllDlm.strings.no_links_found + '</div>');
            }
            return;
        }
        
        var html = '';
        links.forEach(function(link, idx) {
            var domain = extractDomain(link.link);
            var itemId = 'link-item-' + link.post_id + '-' + link.index;
            var checkboxId = 'link-checkbox-' + link.post_id + '-' + link.index;
            var postDate = link.post_date || link.post_date_gmt || '';
            html += '<div class="zibll-dlm-link-item" data-post-id="' + link.post_id + '" data-index="' + link.index + '" data-domain="' + escapeHtml(domain) + '" data-post-date="' + escapeHtml(postDate) + '" id="' + itemId + '">';
            html += '<div class="zibll-dlm-link-checkbox-wrapper">';
            html += '<input type="checkbox" class="zibll-dlm-link-checkbox" id="' + checkboxId + '" data-post-id="' + link.post_id + '" data-index="' + link.index + '">';
            html += '</div>';
            html += '<div class="zibll-dlm-link-info">';
            html += '<div class="zibll-dlm-link-title">';
            html += '<a href="' + link.post_edit_url + '" target="_blank">' + escapeHtml(link.post_title) + '</a>';
            html += ' <span style="color: #999; font-size: 12px;">(ID: ' + link.post_id + ')</span>';
            html += '</div>';
            html += '<div class="zibll-dlm-link-url-wrapper">';
            html += '<span class="zibll-dlm-link-url-display" data-item-id="' + itemId + '">' + escapeHtml(link.link) + '</span>';
            html += '<div class="zibll-dlm-link-url-edit" style="display: none;">';
            html += '<input type="text" class="zibll-dlm-link-input regular-text" value="' + escapeHtml(link.link) + '" data-original="' + escapeHtml(link.link) + '">';
            html += '<button type="button" class="button button-small zibll-dlm-link-save">保存</button>';
            html += '<button type="button" class="button button-small zibll-dlm-link-cancel">取消</button>';
            html += '</div>';
            html += '</div>';
            if (link.name || link.more) {
                html += '<div class="zibll-dlm-link-meta">';
                if (link.name) {
                    html += '名称: ' + escapeHtml(link.name) + ' | ';
                }
                if (link.more) {
                    html += '备注: ' + escapeHtml(link.more);
                }
                html += '</div>';
            }
            html += '</div>';
            html += '</div>';
        });
        
        container.html(html);
        
        // 绑定编辑事件
        bindEditEvents();
        
        // 绑定选择事件
        bindSelectionEvents();
        
        // 显示链接后立即应用筛选
        if (currentFilter.domain || currentFilter.text) {
            applyListFilter();
        }
    }
    
    /**
     * 绑定选择事件
     */
    function bindSelectionEvents() {
        // 单个复选框变化
        $(document).off('change', '.zibll-dlm-link-checkbox').on('change', '.zibll-dlm-link-checkbox', function() {
            updateSelection();
        });
        
        // 全选/取消全选
        $(document).off('change', '#select-all-links').on('change', '#select-all-links', function() {
            var checked = $(this).prop('checked');
            $('.zibll-dlm-link-checkbox').prop('checked', checked);
            updateSelection();
        });
        
        // 取消选择
        $(document).off('click', '#btn-cancel-selection').on('click', '#btn-cancel-selection', function() {
            $('.zibll-dlm-link-checkbox').prop('checked', false);
            $('#select-all-links').prop('checked', false);
            updateSelection();
        });
        
        // 批量替换选中链接
        $(document).off('click', '#btn-batch-replace').on('click', '#btn-batch-replace', function() {
            batchReplaceSelected();
        });
        
        // 批量删除选中链接
        $(document).off('click', '#btn-batch-delete').on('click', '#btn-batch-delete', function() {
            batchDeleteSelected();
        });
        
        // 全选时只选择当前显示的链接
        $(document).off('change', '#select-all-links').on('change', '#select-all-links', function() {
            var checked = $(this).prop('checked');
            // 只选择当前显示的链接（筛选后的）
            $('#links-container .zibll-dlm-link-checkbox').prop('checked', checked);
            updateSelection();
        });
        
        // 回车键批量替换
        $(document).off('keypress', '#batch-replace-search, #batch-replace-value').on('keypress', '#batch-replace-search, #batch-replace-value', function(e) {
            if (e.which === 13) {
                $('#btn-batch-replace').click();
            }
        });
    }
    
    /**
     * 更新选择状态
     */
    function updateSelection() {
        var checked = $('#links-container .zibll-dlm-link-checkbox:checked');
        var total = $('#links-container .zibll-dlm-link-checkbox').length;
        var count = checked.length;
        
        $('#selected-count').text(count);
        
        if (count > 0) {
            $('#batch-toolbar').show();
            $('#batch-replace-toolbar').show();
            
            // 更新全选状态（只针对当前显示的链接）
            $('#select-all-links').prop('checked', count > 0 && count === total);
        } else {
            $('#batch-toolbar').hide();
        }
    }
    
    /**
     * 获取选中的链接
     */
    function getSelectedLinks() {
        var selected = [];
        $('.zibll-dlm-link-checkbox:checked').each(function() {
            selected.push({
                post_id: $(this).data('post-id'),
                index: $(this).data('index')
            });
        });
        return selected;
    }
    
    /**
     * 批量替换选中的链接
     */
    function batchReplaceSelected() {
        var selected = getSelectedLinks();
        if (selected.length === 0) {
            showMessage('error', '请先选择要替换的链接');
            return;
        }
        
        var search = $('#batch-replace-search').val().trim();
        var replace = $('#batch-replace-value').val().trim();
        var targetField = $('#batch-replace-field-selected').val();
        var useRegex = $('#batch-replace-regex').is(':checked');
        
        if (!search) {
            showMessage('error', '查找内容不能为空');
            return;
        }
        
        if (!confirm('确定要替换选中的 ' + selected.length + ' 个链接吗？')) {
            return;
        }
        
        showLoading();
        
        $.ajax({
            url: zibllDlm.ajax_url,
            type: 'POST',
            data: {
                action: 'zibll_dlm_batch_replace_selected',
                nonce: zibllDlm.nonce,
                search: search,
                replace: replace,
                target_field: targetField,
                use_regex: useRegex,
                selected_links: selected
            },
            success: function(response) {
                hideLoading();
                
                if (response.success) {
                    var message = response.data.message;
                    if (response.data.errors && response.data.errors.length > 0) {
                        message += '<br>错误: ' + response.data.errors.join(', ');
                    }
                    showMessage('success', message);
                    // 取消选择并重新搜索
                    $('.zibll-dlm-link-checkbox').prop('checked', false);
                    updateSelection();
                    setTimeout(function() {
                        searchLinks(currentPage);
                    }, 1000);
                } else {
                    showMessage('error', response.data.message || zibllDlm.strings.error);
                }
            },
            error: function() {
                hideLoading();
                showMessage('error', zibllDlm.strings.error);
            }
        });
    }
    
    /**
     * 批量删除选中的链接
     */
    function batchDeleteSelected() {
        var selected = getSelectedLinks();
        if (selected.length === 0) {
            showMessage('error', '请先选择要删除的链接');
            return;
        }
        
        if (!confirm('确定要删除选中的 ' + selected.length + ' 个链接吗？此操作不可恢复！')) {
            return;
        }
        
        showLoading();
        
        $.ajax({
            url: zibllDlm.ajax_url,
            type: 'POST',
            data: {
                action: 'zibll_dlm_batch_delete_selected',
                nonce: zibllDlm.nonce,
                selected_links: selected
            },
            success: function(response) {
                hideLoading();
                
                if (response.success) {
                    var message = response.data.message;
                    if (response.data.errors && response.data.errors.length > 0) {
                        message += '<br>错误: ' + response.data.errors.join(', ');
                    }
                    showMessage('success', message);
                    // 取消选择并重新搜索
                    $('.zibll-dlm-link-checkbox').prop('checked', false);
                    updateSelection();
                    setTimeout(function() {
                        searchLinks();
                    }, 1000);
                } else {
                    showMessage('error', response.data.message || zibllDlm.strings.error);
                }
            },
            error: function() {
                hideLoading();
                showMessage('error', zibllDlm.strings.error);
            }
        });
    }
    
    /**
     * 绑定编辑事件
     */
    function bindEditEvents() {
        // 点击链接显示编辑框
        $(document).off('click', '.zibll-dlm-link-url-display').on('click', '.zibll-dlm-link-url-display', function() {
            var itemId = $(this).data('item-id');
            var $item = $('#' + itemId);
            var $display = $(this);
            var $edit = $item.find('.zibll-dlm-link-url-edit');
            var $input = $edit.find('.zibll-dlm-link-input');
            
            $display.hide();
            $edit.show();
            $input.focus().select();
        });
        
        // 保存链接
        $(document).off('click', '.zibll-dlm-link-save').on('click', '.zibll-dlm-link-save', function() {
            var $item = $(this).closest('.zibll-dlm-link-item');
            var $input = $item.find('.zibll-dlm-link-input');
            var $display = $item.find('.zibll-dlm-link-url-display');
            var $edit = $item.find('.zibll-dlm-link-url-edit');
            
            var postId = $item.data('post-id');
            var index = $item.data('index');
            var newLink = $input.val().trim();
            var originalLink = $input.data('original');
            
            if (!newLink) {
                showMessage('error', '链接不能为空');
                return;
            }
            
            if (newLink === originalLink) {
                $display.show();
                $edit.hide();
                return;
            }
            
            // 显示加载状态
            $input.prop('disabled', true);
            $(this).prop('disabled', true).text('保存中...');
            
            $.ajax({
                url: zibllDlm.ajax_url,
                type: 'POST',
                data: {
                    action: 'zibll_dlm_update_link',
                    nonce: zibllDlm.nonce,
                    post_id: postId,
                    index: index,
                    link: newLink
                },
                success: function(response) {
                    $input.prop('disabled', false);
                    $item.find('.zibll-dlm-link-save').prop('disabled', false).text('保存');
                    
                    if (response.success) {
                        $display.text(newLink).show();
                        $edit.hide();
                        $input.data('original', newLink);
                        showMessage('success', '链接更新成功');
                    } else {
                        showMessage('error', response.data.message || zibllDlm.strings.error);
                    }
                },
                error: function() {
                    $input.prop('disabled', false);
                    $item.find('.zibll-dlm-link-save').prop('disabled', false).text('保存');
                    showMessage('error', zibllDlm.strings.error);
                }
            });
        });
        
        // 取消编辑
        $(document).off('click', '.zibll-dlm-link-cancel').on('click', '.zibll-dlm-link-cancel', function() {
            var $item = $(this).closest('.zibll-dlm-link-item');
            var $input = $item.find('.zibll-dlm-link-input');
            var $display = $item.find('.zibll-dlm-link-url-display');
            var $edit = $item.find('.zibll-dlm-link-url-edit');
            
            // 恢复原始值
            $input.val($input.data('original'));
            $display.show();
            $edit.hide();
        });
        
        // 回车保存
        $(document).off('keypress', '.zibll-dlm-link-input').on('keypress', '.zibll-dlm-link-input', function(e) {
            if (e.which === 13) {
                $(this).closest('.zibll-dlm-link-url-edit').find('.zibll-dlm-link-save').click();
            }
        });
        
        // ESC取消
        $(document).off('keydown', '.zibll-dlm-link-input').on('keydown', '.zibll-dlm-link-input', function(e) {
            if (e.which === 27) {
                $(this).closest('.zibll-dlm-link-url-edit').find('.zibll-dlm-link-cancel').click();
            }
        });
    }
    
    /**
     * 显示加载提示
     */
    function showLoading() {
        $('#zibll-dlm-loading').show();
    }
    
    /**
     * 隐藏加载提示
     */
    function hideLoading() {
        $('#zibll-dlm-loading').hide();
    }
    
    /**
     * 显示消息
     */
    function showMessage(type, message) {
        var notice = $('#zibll-dlm-message');
        notice.removeClass('notice-success notice-error')
              .addClass('notice notice-' + type)
              .html('<p>' + message + '</p>')
              .fadeIn();
        
        setTimeout(function() {
            notice.fadeOut();
        }, 5000);
    }
    
    /**
     * 提取域名
     */
    function extractDomain(url) {
        try {
            var a = document.createElement('a');
            a.href = url;
            return a.hostname;
        } catch(e) {
            return '';
        }
    }
    
    /**
     * 导出链接
     */
    function exportLinks() {
        if (currentLinks.length === 0) {
            showMessage('error', '请先搜索链接');
            return;
        }
        
        showLoading();
        
        // 创建表单并提交
        var form = $('<form>', {
            'method': 'POST',
            'action': zibllDlm.ajax_url,
            'target': '_blank'
        });
        
        form.append($('<input>', {
            'type': 'hidden',
            'name': 'action',
            'value': 'zibll_dlm_export'
        }));
        
        form.append($('<input>', {
            'type': 'hidden',
            'name': 'nonce',
            'value': zibllDlm.nonce
        }));
        
        form.append($('<input>', {
            'type': 'hidden',
            'name': 'title',
            'value': $('#filter-title').val()
        }));
        
        form.append($('<input>', {
            'type': 'hidden',
            'name': 'search',
            'value': $('#filter-search').val()
        }));
        
        form.append($('<input>', {
            'type': 'hidden',
            'name': 'domain',
            'value': $('#filter-domain').val()
        }));
        
        form.append($('<input>', {
            'type': 'hidden',
            'name': 'use_regex',
            'value': $('#filter-use-regex').is(':checked')
        }));
        
        $('body').append(form);
        form.submit();
        form.remove();
        
        setTimeout(function() {
            hideLoading();
            showMessage('success', '导出成功！文件已开始下载');
        }, 500);
    }
    
    /**
     * 导入链接
     */
    function importLinks() {
        var fileInput = $('#import-file')[0];
        
        if (!fileInput.files || !fileInput.files[0]) {
            showMessage('error', '请选择要导入的CSV文件');
            return;
        }
        
        var file = fileInput.files[0];
        
        if (!file.name.toLowerCase().endsWith('.csv')) {
            showMessage('error', '只支持CSV格式文件');
            return;
        }
        
        if (!confirm('确定要导入此文件吗？导入的链接将添加到对应文章的下载资源中。')) {
            return;
        }
        
        showLoading();
        
        var formData = new FormData();
        formData.append('action', 'zibll_dlm_import');
        formData.append('nonce', zibllDlm.nonce);
        formData.append('import_file', file);
        
        $.ajax({
            url: zibllDlm.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                hideLoading();
                
                if (response.success) {
                    var message = response.data.message;
                    var hasWarnings = response.data.warnings && response.data.warnings.length > 0;
                    var hasErrors = response.data.errors && response.data.errors.length > 0;
                    
                    if (hasWarnings) {
                        var warningList = response.data.warnings.slice(0, 10).join('<br>');
                        if (response.data.warnings.length > 10) {
                            warningList += '<br>...还有 ' + (response.data.warnings.length - 10) + ' 条警告';
                        }
                        message += '<br><br><strong style="color:#d63638">警告详情：</strong><br>' + warningList;
                    }
                    
                    if (hasErrors) {
                        var errorList = response.data.errors.slice(0, 10).join('<br>');
                        if (response.data.errors.length > 10) {
                            errorList += '<br>...还有 ' + (response.data.errors.length - 10) + ' 条错误';
                        }
                        message += '<br><br><strong style="color:#d63638">错误详情：</strong><br>' + errorList;
                    }
                    
                    // 如果有警告或错误，使用警告样式；否则使用成功样式
                    var messageType = (hasWarnings || hasErrors) ? 'error' : 'success';
                    showMessage(messageType, message);
                    // 清空文件选择
                    $('#import-file').val('');
                } else {
                    showMessage('error', response.data.message || zibllDlm.strings.error);
                }
            },
            error: function() {
                hideLoading();
                showMessage('error', zibllDlm.strings.error);
            }
        });
    }
    
    /**
     * 加载日志
     */
    function loadLogs() {
        $.ajax({
            url: zibllDlm.ajax_url,
            type: 'POST',
            data: {
                action: 'zibll_dlm_get_logs',
                nonce: zibllDlm.nonce
            },
            success: function(response) {
                if (response.success) {
                    var logs = response.data.logs;
                    var html = '';
                    
                    if (logs.length === 0) {
                        html = '<tr><td colspan="5">暂无日志记录</td></tr>';
                    } else {
                        logs.forEach(function(log) {
                            var typeLabel = '';
                            switch(log.action_type) {
                                case 'update_link': typeLabel = '<span style="color:#00a32a">更新链接</span>'; break;
                                case 'batch_replace': typeLabel = '<span style="color:#2271b1">批量替换</span>'; break;
                                case 'batch_delete': typeLabel = '<span style="color:#d63638">批量删除</span>'; break;
                                default: typeLabel = log.action_type;
                            }
                            
                            html += '<tr>';
                            html += '<td>' + log.time + '</td>';
                            html += '<td>' + escapeHtml(log.user_name) + '</td>';
                            html += '<td>' + typeLabel + '</td>';
                            html += '<td>' + escapeHtml(log.details) + '</td>';
                            html += '<td>' + log.affected_count + '</td>';
                            html += '</tr>';
                        });
                    }
                    
                    $('#logs-body').html(html);
                }
            }
        });
    }

    /**
     * HTML转义
     */
    function escapeHtml(text) {
        var map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }
    
})(jQuery);

