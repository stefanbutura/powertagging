(function ($) {
  Drupal.behaviors.powertagging_widget = {
    attach: function (context) {

      var PTContainers = new Containers();

      /**
       * Set the "autocomplete" event for the search field.
       *
       * This field is for the manually adding of concepts or free terms.
       */
      $(context).find("input.powertagging_autocomplete_tags").once("powertagging_widget").each(function () {
        var pt_field_id = $(this).data("drupal-selector").replace(/^edit-(.*)-powertagging-manual$/, "$1").replace(/-/g, "_");
        var settings = drupalSettings.powertagging[pt_field_id];

        if (typeof settings !== "undefined") {
          $(this).autocomplete({
            source: drupalSettings.path.baseUrl + "powertagging/autocomplete-tags/" + settings.settings.powertagging_id + '/' + settings.settings.entity_language,
            minLength: 2,
            select: function (event, ui) {
              event.preventDefault();
              PTContainers.resultArea(pt_field_id).addTag({
                tid: ui.item.tid,
                uri: ui.item.uri,
                label: ui.item.label,
                type: ui.item.type,
                score: 100
              });
              $(this).val('');
            }
          }).keyup(function (e) {
            if (e.keyCode === 13) {
              var field_value = jQuery.trim($(this).val());
              if (field_value.length > 0) {
                PTContainers.resultArea(pt_field_id).addTag({
                  tid: 0,
                  uri: '',
                  label: field_value,
                  type: "freeterm",
                  score: 100
                });
                $(this).autocomplete("close");
                $(this).val("");
              }
            }
          });
        }
      });

      /**
       * Set the "click" event to the "Get tags" button.
       */
      $(context).find("div.field--type-powertagging-tags").once("powertagging_widget").each(function () {
        $(this).find("input[type=\"submit\"]").click(function (event) {
          event.preventDefault();

          var pt_field_id = $(this).data('drupal-selector').replace(/^edit-(.*)-powertagging-get-tags$/, "$1").replace(/-/g, "_");
          var data = collectContent(pt_field_id);
          PTContainers.extractionArea(pt_field_id).loading();
          $.post(drupalSettings.path.baseUrl + "powertagging/extract/" + data.settings.powertagging_id, data, function (tags) {
            renderResult(pt_field_id, tags);
          }, "json");
        });
      });

      /**
       * Set the change event of the language switcher.
       */
      $(context).find("#edit-langcode-wrapper").once("powertagging_widget").each(function () {
        $(this).find("select, input").change(function () {
          var language = '';
          // Language selection as a select-element.
          if ($(this).is('select')) {
            language = $(this).val();
          }
          // Language selection as radio buttons.
          else if ($(this).find('input:checked').length > 0) {
            language = $(this).find('input:checked').val();
          }

          Object.getOwnPropertyNames(drupalSettings.powertagging).forEach(function (pt_field_id) {
            drupalSettings.powertagging[pt_field_id].settings.entity_language = language;
            var settings = drupalSettings.powertagging[pt_field_id];
            var html_field_id = "#edit-" + pt_field_id.replace(/_/g, '-') + "-wrapper";
            checkEntityLanguage(pt_field_id, settings.settings);

            // Update the autocomplete path.
            $(html_field_id).find("input.powertagging_autocomplete_tags").autocomplete(
                'option', 'source', drupalSettings.path.baseUrl + "powertagging/autocomplete-tags/" + settings.settings.powertagging_id + '/' + settings.settings.entity_language
            );
          });
        });
      });

      /**
       * Collect all the data into an object for the extraction.
       *
       * @param {string} pt_field_id
       *   The PowerTagging field ID.
       *
       * @return {Object}
       *   The collected data from the form.
       */
      function collectContent(pt_field_id) {
        var settings = drupalSettings.powertagging[pt_field_id];
        var data = {settings: settings.settings, content: "", files: []};

        // Build the text content to extract tags from.
        $.each(settings.fields, function (field_index, field) {
          switch (field.module) {
            case "core":
            case "text":
              var text_content = collectContentText(field.field_name, field.widget);
              if (text_content.length > 0) {
                data.content += " " + text_content;
              }
              break;

            case "media":
            case "file":
              var files = collectContentFile(field.field_name, field.widget);
              if (files.length > 0) {
                data.files = data.files.concat(files);
              }
              break;
          }
        });

        return data;
      }

      /**
       * Collect the data from different text field types.
       *
       * @param {string} field
       *   The text field.
       * @param {string} widget
       *   The configured widget for this field.
       *
       * @return {string}
       *   The entered text from this field.
       */
      function collectContentText(field, widget) {
        var field_id = "#edit-" + field.replace(/_/g, "-") + "-wrapper";
        var content = "";

        switch (widget) {
          case "string_textfield":
          case "text_textfield":
            content += $(field_id + " input").val();
            break;

          case "string_textarea":
          case "text_textarea":
          case "text_textarea_with_summary":
            // Get the text from the summary and the full text.
            $(field_id + " textarea").each(function () {
              var textarea_id = $(this).attr("id");
              // CkEditor.
              if (typeof(CKEDITOR) !== "undefined" && CKEDITOR.hasOwnProperty("instances") && CKEDITOR.instances.hasOwnProperty(textarea_id)) {
                content += CKEDITOR.instances[textarea_id].getData();
              }
              // TinyMCE.
              else if (typeof(tinyMCE) !== "undefined" && tinyMCE.hasOwnProperty("editors") && tinyMCE.editors.hasOwnProperty(textarea_id)) {
                content += tinyMCE.editors[textarea_id].getContent({format: "raw"});
              }
              // No text editor or an unsupported one.
              else {
                content += $(this).val();
              }
            });
            break;
        }

        return content.trim();
      }

      /**
       * Collect the data from different file field types.
       *
       * @param {string} field
       *   The file field.
       * @param {string} widget
       *   The configured widget for this field.
       *
       * @return {Array}
       *   The uploaded files from this field.
       */
      function collectContentFile(field, widget) {
        var field_id = "#edit-" + field.replace(/_/g, "-") + "-wrapper";
        var files = [];

        switch (widget) {
          case "file_generic":
          case "media_generic":
            $(field_id + " input[type=hidden]").each(function () {
              if ($(this).attr("name").indexOf("[fids]") > 0 && $(this).val() > 0) {
                files.push($(this).val());
              }
            });
            return files;
            break;
        }

        return files;
      }

      /**
       * Render the PowerTagging data.
       *
       * @param {string} pt_field_id
       *   The PowerTagging field ID.
       * @param {Array} tags
       *   The extracted tags.
       */
      function renderResult(pt_field_id, tags) {
        // Close the extracted tags container on the beginning.
        if (!tags.hasOwnProperty("suggestion")) {
          PTContainers.extractionArea(pt_field_id).hide();
        }

        var settings = drupalSettings.powertagging[pt_field_id];
        checkEntityLanguage(pt_field_id, settings.settings);

        // Render the tags if no message is given.
        if (!tags.hasOwnProperty("messages") || tags.messages.length === 0) {
          var tags_container = '';
          var rendered_tags;

          // Render the content tags.
          if (tags.hasOwnProperty("content")) {
            var content_tags = tags.content.concepts.concat(tags.content.freeterms);
            if (content_tags.length) {
              rendered_tags = [];

              content_tags.forEach(function (tag) {
                rendered_tags.push(renderTag(tag));
              });

              tags_container = '<div class="powertagging-extracted-tags-area"><div class="powertagging-extraction-label">' + Drupal.t("Text fields") + "</div>";
              tags_container += "<ul><li>" + rendered_tags.join("</li><li>") + "</li></ul></div>";
              PTContainers.extractionArea(pt_field_id).append(tags_container).show();
            }
          }

          // Render the file tags.
          if (tags.hasOwnProperty('files')) {
            for (var file_name in tags.files) {
              if (tags.files.hasOwnProperty(file_name)) {
                var file_tags = tags.files[file_name].concepts.concat(tags.files[file_name].freeterms);
                if (file_tags.length) {
                  rendered_tags = [];

                  file_tags.forEach(function (tag) {
                    rendered_tags.push(renderTag(tag));
                  });

                  tags_container = '<div class="powertagging-extracted-tags-area"><div class="powertagging-extraction-label">' + Drupal.t('Uploaded file "%file"', {'%file': file_name}) + "</div>";
                  tags_container += "<ul><li>" + rendered_tags.join("</li><li>") + "</li></ul></div>";
                  PTContainers.extractionArea(pt_field_id).append(tags_container).show();
                }
              }
            }
          }

          // Initially add the tags for the results area.
          var result_tags = [];

          // There are already tags connected with this node.
          if (settings.hasOwnProperty("selected_tags") && settings.selected_tags.length) {
            settings.selected_tags.forEach(function (tag) {
              result_tags.push(tag);
            });
          }
          // No tags connected with this node yet, use the suggestion.
          else if (tags.hasOwnProperty("suggestion")) {
            var suggestion_tags = tags.suggestion.concepts.concat(tags.suggestion.freeterms);
            if (suggestion_tags.length) {
              suggestion_tags.forEach(function (tag) {
                result_tags.push(tag);
              });
            }
          }

          // Add the tags to the result.
          result_tags.forEach(function (result_tag) {
            PTContainers.resultArea(pt_field_id).addTag(result_tag);
          });
        }
        // There are errors or infos available --> show them instead of the
        // tags.
        else {
          var messages_html = '';
          tags.messages.forEach(function (message) {
            messages_html += '<div class="messages messages--' + message.type + '">' + message.message + '</div>';
          });
          PTContainers.extractionArea(pt_field_id).append(messages_html).show();
        }

        // Add the click handlers to the tag elements.
        PTContainers.extractionArea(pt_field_id).addClick();
        PTContainers.resultArea(pt_field_id).addClick();
      }

      /**
       * Get the HTML code of a single PowerTagging tag.
       *
       * @param {Object} tag
       *   The tag with properties: type, tid, label, uri and score.
       *
       * @return {string}
       *   The HTML output of the tag.
       */
      function renderTag(tag) {
        var score = tag.score ? ' (' + tag.score + ')' : '';
        return '<div class="powertagging-tag ' + tag.type + '" data-tid="' + tag.tid + '" data-label="' + tag.label + '" data-uri="' + tag.uri + '" data-score="' + tag.score + '">' + tag.label + score + '</div>';
      }

      /**
       * Create a JS-object out of a jQuery element for a PowerTagging tag.
       *
       * @param {Object} tag_element
       *   The jQuery element for a PowerTagging tag.
       *
       * @return {Object}
       *   The tag as a JS object.
       */
      function tagElementToObject(tag_element) {
        var type = tag_element.hasClass('concept') ? 'concept' : 'freeterm';
        return {
          tid: tag_element.attr("data-tid"),
          uri: tag_element.attr("data-uri"),
          label: tag_element.attr("data-label"),
          type: type,
          score: tag_element.attr("data-score")
        };
      }

      /**
       * Check if the currently selected entity language is allowed
       *
       * @param {string} pt_field_id
       *   The PowerTagging field ID.
       * @param {Array} settings
       *   An array of settings of the PowerTagging field.
       */
      function checkEntityLanguage(pt_field_id, settings) {
        var language_error_element = $('#edit-' + pt_field_id.replace(/_/g, "-") + '-powertagging-language-error');
        // The currently selected entity language is allowed.
        if ($.inArray(settings.entity_language, settings.allowed_languages) > -1) {
          language_error_element.hide();
        }
        // The currently selected entity language is not allowed.
        else {
          language_error_element.show();
        }
      }

      /**
       * PowerTagging containers for the extraction and result container.
       *
       * @constructor
       */
      function Containers() {
        this.containers = [];
        this.containers["extraction"] = [];
        this.containers["result"] = [];
      }

      /**
       * Adds a new PowerTagging field to the containers object.
       *
       * @param {string} pt_field_id
       *   The PowerTagging field ID.
       */
      Containers.prototype.add = function (pt_field_id) {
        var pt_field = "#edit-" + pt_field_id.replace(/_/g, "-") + "-wrapper";
        this.containers["extraction"][pt_field_id] = new ExtractionContainer(pt_field_id, pt_field);
        this.containers["result"][pt_field_id] = new ResultContainer(pt_field_id, pt_field);
      };

      /**
       * Get the extraction container of an PowerTagging field.
       *
       * @param {string} pt_field_id
       *   The PowerTagging field ID.
       *
       * @return {ExtractionContainer}
       *   The extraction container object.
       */
      Containers.prototype.extractionArea = function (pt_field_id) {
        return this.containers["extraction"][pt_field_id];
      };

      /**
       * Get the result container of an PowerTagging field.
       *
       * @param {string} pt_field_id
       *   The PowerTagging field ID.
       *
       * @return {ResultContainer}
       *   The result container object.
       */
      Containers.prototype.resultArea = function (pt_field_id) {
        return this.containers["result"][pt_field_id];
      };

      /**
       * An extraction container object of a PowerTagging field.
       *
       * @param {string} pt_field_id
       *   The PowerTagging field ID.
       * @param {string} pt_field
       *   The ID of the DOM element of the PowerTagging field.
       *
       * @constructor
       */
      function ExtractionContainer(pt_field_id, pt_field) {
        this.pt_field_id = pt_field_id;
        this.field = pt_field + " .powertagging-extracted-tags";
      }

      /**
       * Shows the extraction container.
       */
      ExtractionContainer.prototype.show = function () {
        $(this.field).slideDown();
        $(this.field).prev().slideUp();
        $(this.field).parent().slideDown();
      };

      /**
       * Hides the extraction container.
       */
      ExtractionContainer.prototype.hide = function () {
        $(this.field).parent().hide();
        $(this.field).prev().hide();
        $(this.field).hide();
      };

      /**
       * Shows the loading sign.
       */
      ExtractionContainer.prototype.loading = function () {
        $(this.field).slideUp().html("");
        $(this.field).prev().slideDown();
        $(this.field).parent().slideDown();
      };

      /**
       * Appends a HTML text to the extracted terms container.
       *
       * @param {string} html
       *   The HTML text.
       *
       * @return {ExtractionContainer}
       */
      ExtractionContainer.prototype.append = function (html) {
        $(this.field).append(html);
        return this;
      };

      /**
       * Marks a tag in the extracted term container as disabled.
       *
       * @param {Object} tag
       *   The tag object.
       */
      ExtractionContainer.prototype.disableTag = function (tag) {
        if (tag.tid > 0) {
          $(this.field + ' .powertagging-tag[data-tid="' + tag.tid + '"]').addClass("disabled");
        }
        else {
          $(this.field + ' .powertagging-tag[data-label="' + tag.label + '"]').addClass("disabled");
        }
      };

      /**
       * Removes the disabled marker of a tag in the extracted term container.
       *
       * @param {Object} tag
       *   The tag object.
       */
      ExtractionContainer.prototype.enableTag = function (tag) {
        if (tag.tid > 0) {
          $(this.field + ' .powertagging-tag[data-tid="' + tag.tid + '"]').removeClass("disabled");
        }
        else {
          $(this.field + ' .powertagging-tag[data-label="' + tag.label + '"]').removeClass("disabled");
        }
      };

      /**
       * Get the highest score for all the same extracted tags.
       *
       * @param {Object} tag
       *   The tag object.
       */
      ExtractionContainer.prototype.getHighestScore = function (tag) {
        var tags = [];
        if (tag.tid > 0) {
          tags = $(this.field + ' .powertagging-tag[data-tid="' + tag.tid + '"]');
        }
        else {
          tags = $(this.field + ' .powertagging-tag[data-label="' + tag.label + '"]');
        }
        tags.each(function (index) {
          var score = $(this).attr('data-score');
          if (parseInt(score) > parseInt(tag.score)) {
            tag.score = score;
          }
        });
      };

      /**
       * Adds the click event to all the tags in the extracted tags list.
       */
      ExtractionContainer.prototype.addClick = function () {
        var pt_field_id = this.pt_field_id;
        $(this.field + " .powertagging-tag").once("powertagging_widget").each(function () {
          $(this).click(function () {
            if ($(this).hasClass('disabled')) {
              PTContainers.resultArea(pt_field_id).removeTag(tagElementToObject($(this)));
            }
            else {
              PTContainers.resultArea(pt_field_id).addTag(tagElementToObject($(this)));
            }
          });
        });
      };

      /**
       * A result container object of a PowerTagging field.
       *
       * @param {string} pt_field_id
       *   The PowerTagging field ID.
       * @param {string} pt_field
       *   The ID of the DOM element of the PowerTagging field.
       *
       * @constructor
       */
      function ResultContainer(pt_field_id, pt_field) {
        this.pt_field_id = pt_field_id;
        this.pt_field = pt_field;
        this.field = pt_field + " .powertagging-tag-result";
      }

      /**
       * Adds a tag to the result list.
       *
       * @param {Object} tag
       *   The tag object.
       */
      ResultContainer.prototype.addTag = function (tag) {
        var field = this.field;

        // Only add tags, that are not already inside the results area.
        if ((tag.tid > 0 && $(field + ' .powertagging-tag[data-tid="' + tag.tid + '"]').length === 0) ||
            (tag.tid === 0 && ($(field + ' .powertagging-tag[data-label="' + tag.label + '"]').length === 0 || tag.uri !== ""))) {

          // Add a new list if this is the first tag to add.
          if ($(field + " ul").length === 0) {
            $(field).find(".no-tags").hide();
            $(field).append("<ul></ul>");
          }

          // Remove free terms with the same string for concepts.
          if (tag.type === 'concept') {
            $(field + ' .powertagging-tag[data-label="' + tag.label + '"]').parent("li").remove();
          }

          // Add a new list item to the result.
          PTContainers.extractionArea(this.pt_field_id).getHighestScore(tag);
          $(field + " ul").append("<li>" + renderTag(tag) + "</li>");

          // Add a click handler to the new result tag.
          var thisContainer = this;
          $(field + " li:last-child .powertagging-tag").click(function () {
            thisContainer.removeTag(tagElementToObject($(this)));
          });

          // Update the field value to save.
          this.updateFieldValue();
        }

        // Disable already selected tags in the extraction area.
        PTContainers.extractionArea(this.pt_field_id).disableTag(tag);
      };

      /**
       * Removes a tag from the result list.
       *
       * @param {Object} tag
       *   The tag object.
       */
      ResultContainer.prototype.removeTag = function (tag) {
        var field = this.field;

        // Remove tag from the list.
        if (tag.tid > 0) {
          $(field + ' .powertagging-tag[data-tid="' + tag.tid + '"]').parent("li").remove();
        }
        else {
          $(field + ' .powertagging-tag[data-label="' + tag.label + '"]').parent("li").remove();
        }

        // No empty list are allowed, remove them.
        if ($(field + " li").length === 0) {
          $(field).find(".no-tags").show();
          $(field + " ul").remove();
        }

        // Enable tag in the extraction area again.
        PTContainers.extractionArea(this.pt_field_id).enableTag(tag);

        // Also remove the tag from the selected tags in the Drupal settings.
        var settings = drupalSettings.powertagging[this.pt_field_id];
        if (settings.hasOwnProperty("selected_tags") && settings.selected_tags.length > 0) {
          for (var tag_index = 0; tag_index < settings.selected_tags.length; tag_index++) {
            if (settings.selected_tags[tag_index].label === tag.label) {
              settings.selected_tags.splice(tag_index, 1);
              break;
            }
          }
        }

        // Update the field value to save.
        this.updateFieldValue();
      };

      /**
       * Updates the value field for the tags.
       */
      ResultContainer.prototype.updateFieldValue = function () {
        var tags_to_save = [];
        var tag = '';
        // Use tid for existing terms and label for new free terms.
        $(this.field + " .powertagging-tag").each(function () {
          if ($(this).attr("data-tid") > 0) {
            tag = $(this).attr("data-tid");
          }
          else {
            tag = $(this).attr("data-label").replace(',', ';') + '|' + $(this).attr("data-uri");
          }
          tags_to_save.push(tag + '#' + $(this).attr("data-score"));
        });

        $(this.pt_field).find("input.powertagging_tag_string").val(tags_to_save.join(','));
      };

      /**
       * Adds the click event to all the tags in the result list.
       */
      ResultContainer.prototype.addClick = function () {
        var thisContainer = this;
        $(this.field + " .powertagging-tag").once("powertagging_widget").each(function () {
          $(this).click(function () {
            thisContainer.removeTag(tagElementToObject($(this)));
          });
        });
      };

      /**
       * Initialize the PowerTagging mechanism.
       */
      $(document).ready(function () {
        // Initialize the PowerTagging fields if it is shown in a form.
        if (typeof drupalSettings.powertagging !== 'undefined' && $("#edit-default-value-input").length === 0) {
          Object.getOwnPropertyNames(drupalSettings.powertagging).forEach(function (pt_field_id) {
            PTContainers.add(pt_field_id);
            renderResult(pt_field_id, []);
          });
        }
        else {
          $("#edit-default-value-input").hide();
        }
      });

    }
  }
})(jQuery);