"use strict";
export const apiError = validate20;
const schema31 = {"$schema":"https://json-schema.org/draft/2020-12/schema","$id":"https://transcribed-subtitle-extension.local/schemas/api-error.schema.json","title":"ApiError","type":"object","additionalProperties":false,"required":["error"],"properties":{"error":{"$ref":"error-object.schema.json"},"requestId":{"type":"string","minLength":1,"description":"Backend log correlation ID when available."}}};
const schema32 = {"$schema":"https://json-schema.org/draft/2020-12/schema","$id":"https://transcribed-subtitle-extension.local/schemas/error-object.schema.json","title":"ErrorObject","type":"object","additionalProperties":false,"required":["code","message"],"properties":{"code":{"type":"string","enum":["validation_failed","unauthorized","unsupported_video","audio_unavailable","audio_acquisition_failed","queue_publication_failed","generation_cancelled","generation_not_cancellable","transcription_failed","enrichment_failed","rate_limited","not_found","expired","lyrics_correction_in_progress","lyrics_incomplete","lyrics_do_not_match","lyrics_correction_failed","internal_error","provider_not_configured","provider_unavailable"]},"message":{"type":"string","minLength":1},"details":{"type":"object","additionalProperties":true,"properties":{"reason":{"type":"string","enum":["stale_track"]}}}}};
const func1 = (function ucs2length(str) {
    const len = str.length;
    let length = 0;
    let pos = 0;
    let value;
    while (pos < len) {
        length++;
        value = str.charCodeAt(pos++);
        if (value >= 0xd800 && value <= 0xdbff && pos < len) {
            // high surrogate, and there is a next character
            value = str.charCodeAt(pos);
            if ((value & 0xfc00) === 0xdc00)
                pos++; // low surrogate
        }
    }
    return length;
});

function validate20(data, {instancePath="", parentData, parentDataProperty, rootData=data, dynamicAnchors={}}={}){
/*# sourceURL="https://transcribed-subtitle-extension.local/schemas/api-error.schema.json" */;
let vErrors = null;
let errors = 0;
const evaluated0 = validate20.evaluated;
if(evaluated0.dynamicProps){
evaluated0.props = undefined;
}
if(evaluated0.dynamicItems){
evaluated0.items = undefined;
}
if(errors === 0){
if(data && typeof data == "object" && !Array.isArray(data)){
let missing0;
if((data.error === undefined) && (missing0 = "error")){
validate20.errors = [{instancePath,schemaPath:"#/required",keyword:"required",params:{missingProperty: missing0},message:"must have required property '"+missing0+"'"}];
return false;
}
else {
const _errs1 = errors;
for(const key0 in data){
if(!((key0 === "error") || (key0 === "requestId"))){
validate20.errors = [{instancePath,schemaPath:"#/additionalProperties",keyword:"additionalProperties",params:{additionalProperty: key0},message:"must NOT have additional properties"}];
return false;
break;
}
}
if(_errs1 === errors){
if(data.error !== undefined){
let data0 = data.error;
const _errs2 = errors;
const _errs3 = errors;
if(errors === _errs3){
if(data0 && typeof data0 == "object" && !Array.isArray(data0)){
let missing1;
if(((data0.code === undefined) && (missing1 = "code")) || ((data0.message === undefined) && (missing1 = "message"))){
validate20.errors = [{instancePath:instancePath+"/error",schemaPath:"error-object.schema.json/required",keyword:"required",params:{missingProperty: missing1},message:"must have required property '"+missing1+"'"}];
return false;
}
else {
const _errs5 = errors;
for(const key1 in data0){
if(!(((key1 === "code") || (key1 === "message")) || (key1 === "details"))){
validate20.errors = [{instancePath:instancePath+"/error",schemaPath:"error-object.schema.json/additionalProperties",keyword:"additionalProperties",params:{additionalProperty: key1},message:"must NOT have additional properties"}];
return false;
break;
}
}
if(_errs5 === errors){
if(data0.code !== undefined){
let data1 = data0.code;
const _errs6 = errors;
if(typeof data1 !== "string"){
validate20.errors = [{instancePath:instancePath+"/error/code",schemaPath:"error-object.schema.json/properties/code/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
if(!((((((((((((((((((((data1 === "validation_failed") || (data1 === "unauthorized")) || (data1 === "unsupported_video")) || (data1 === "audio_unavailable")) || (data1 === "audio_acquisition_failed")) || (data1 === "queue_publication_failed")) || (data1 === "generation_cancelled")) || (data1 === "generation_not_cancellable")) || (data1 === "transcription_failed")) || (data1 === "enrichment_failed")) || (data1 === "rate_limited")) || (data1 === "not_found")) || (data1 === "expired")) || (data1 === "lyrics_correction_in_progress")) || (data1 === "lyrics_incomplete")) || (data1 === "lyrics_do_not_match")) || (data1 === "lyrics_correction_failed")) || (data1 === "internal_error")) || (data1 === "provider_not_configured")) || (data1 === "provider_unavailable"))){
validate20.errors = [{instancePath:instancePath+"/error/code",schemaPath:"error-object.schema.json/properties/code/enum",keyword:"enum",params:{allowedValues: schema32.properties.code.enum},message:"must be equal to one of the allowed values"}];
return false;
}
var valid2 = _errs6 === errors;
}
else {
var valid2 = true;
}
if(valid2){
if(data0.message !== undefined){
let data2 = data0.message;
const _errs8 = errors;
if(errors === _errs8){
if(typeof data2 === "string"){
if(func1(data2) < 1){
validate20.errors = [{instancePath:instancePath+"/error/message",schemaPath:"error-object.schema.json/properties/message/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
}
else {
validate20.errors = [{instancePath:instancePath+"/error/message",schemaPath:"error-object.schema.json/properties/message/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid2 = _errs8 === errors;
}
else {
var valid2 = true;
}
if(valid2){
if(data0.details !== undefined){
let data3 = data0.details;
const _errs10 = errors;
if(errors === _errs10){
if(data3 && typeof data3 == "object" && !Array.isArray(data3)){
if(data3.reason !== undefined){
let data4 = data3.reason;
if(typeof data4 !== "string"){
validate20.errors = [{instancePath:instancePath+"/error/details/reason",schemaPath:"error-object.schema.json/properties/details/properties/reason/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
if(!(data4 === "stale_track")){
validate20.errors = [{instancePath:instancePath+"/error/details/reason",schemaPath:"error-object.schema.json/properties/details/properties/reason/enum",keyword:"enum",params:{allowedValues: schema32.properties.details.properties.reason.enum},message:"must be equal to one of the allowed values"}];
return false;
}
}
}
else {
validate20.errors = [{instancePath:instancePath+"/error/details",schemaPath:"error-object.schema.json/properties/details/type",keyword:"type",params:{type: "object"},message:"must be object"}];
return false;
}
}
var valid2 = _errs10 === errors;
}
else {
var valid2 = true;
}
}
}
}
}
}
else {
validate20.errors = [{instancePath:instancePath+"/error",schemaPath:"error-object.schema.json/type",keyword:"type",params:{type: "object"},message:"must be object"}];
return false;
}
}
var valid0 = _errs2 === errors;
}
else {
var valid0 = true;
}
if(valid0){
if(data.requestId !== undefined){
let data5 = data.requestId;
const _errs15 = errors;
if(errors === _errs15){
if(typeof data5 === "string"){
if(func1(data5) < 1){
validate20.errors = [{instancePath:instancePath+"/requestId",schemaPath:"#/properties/requestId/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
}
else {
validate20.errors = [{instancePath:instancePath+"/requestId",schemaPath:"#/properties/requestId/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid0 = _errs15 === errors;
}
else {
var valid0 = true;
}
}
}
}
}
else {
validate20.errors = [{instancePath,schemaPath:"#/type",keyword:"type",params:{type: "object"},message:"must be object"}];
return false;
}
}
validate20.errors = vErrors;
return errors === 0;
}
validate20.evaluated = {"props":true,"dynamicProps":false,"dynamicItems":false};

export const claudeAccount = validate21;
const schema33 = {"$schema":"https://json-schema.org/draft/2020-12/schema","$id":"https://transcribed-subtitle-extension.local/schemas/claude-account.schema.json","title":"ClaudeAccount","type":"object","additionalProperties":false,"required":["available","connected","email","login"],"properties":{"available":{"type":"boolean"},"connected":{"type":"boolean"},"email":{"type":["string","null"],"maxLength":320},"login":{"anyOf":[{"type":"null"},{"type":"object","additionalProperties":false,"required":["status"],"properties":{"status":{"type":"string","enum":["pending","awaiting_code","verifying","failed"]},"authUrl":{"type":"string","format":"uri","maxLength":4096,"pattern":"^https://(?:claude\\.com|claude\\.ai)(?:[/?#][^\\s\\u0000-\\u001F\\u007F\\\\]*)?$"}}}]},"error":{"type":"string","minLength":1,"maxLength":2000}}};
const formats0 = /^(?:[a-z][a-z0-9+\-.]*:)(?:\/?\/)?[^\s]*$/i;
const pattern4 = new RegExp("^https://(?:claude\\.com|claude\\.ai)(?:[/?#][^\\s\\u0000-\\u001F\\u007F\\\\]*)?$", "u");

function validate21(data, {instancePath="", parentData, parentDataProperty, rootData=data, dynamicAnchors={}}={}){
/*# sourceURL="https://transcribed-subtitle-extension.local/schemas/claude-account.schema.json" */;
let vErrors = null;
let errors = 0;
const evaluated0 = validate21.evaluated;
if(evaluated0.dynamicProps){
evaluated0.props = undefined;
}
if(evaluated0.dynamicItems){
evaluated0.items = undefined;
}
if(errors === 0){
if(data && typeof data == "object" && !Array.isArray(data)){
let missing0;
if(((((data.available === undefined) && (missing0 = "available")) || ((data.connected === undefined) && (missing0 = "connected"))) || ((data.email === undefined) && (missing0 = "email"))) || ((data.login === undefined) && (missing0 = "login"))){
validate21.errors = [{instancePath,schemaPath:"#/required",keyword:"required",params:{missingProperty: missing0},message:"must have required property '"+missing0+"'"}];
return false;
}
else {
const _errs1 = errors;
for(const key0 in data){
if(!(((((key0 === "available") || (key0 === "connected")) || (key0 === "email")) || (key0 === "login")) || (key0 === "error"))){
validate21.errors = [{instancePath,schemaPath:"#/additionalProperties",keyword:"additionalProperties",params:{additionalProperty: key0},message:"must NOT have additional properties"}];
return false;
break;
}
}
if(_errs1 === errors){
if(data.available !== undefined){
const _errs2 = errors;
if(typeof data.available !== "boolean"){
validate21.errors = [{instancePath:instancePath+"/available",schemaPath:"#/properties/available/type",keyword:"type",params:{type: "boolean"},message:"must be boolean"}];
return false;
}
var valid0 = _errs2 === errors;
}
else {
var valid0 = true;
}
if(valid0){
if(data.connected !== undefined){
const _errs4 = errors;
if(typeof data.connected !== "boolean"){
validate21.errors = [{instancePath:instancePath+"/connected",schemaPath:"#/properties/connected/type",keyword:"type",params:{type: "boolean"},message:"must be boolean"}];
return false;
}
var valid0 = _errs4 === errors;
}
else {
var valid0 = true;
}
if(valid0){
if(data.email !== undefined){
let data2 = data.email;
const _errs6 = errors;
if((typeof data2 !== "string") && (data2 !== null)){
validate21.errors = [{instancePath:instancePath+"/email",schemaPath:"#/properties/email/type",keyword:"type",params:{type: schema33.properties.email.type},message:"must be string,null"}];
return false;
}
if(errors === _errs6){
if(typeof data2 === "string"){
if(func1(data2) > 320){
validate21.errors = [{instancePath:instancePath+"/email",schemaPath:"#/properties/email/maxLength",keyword:"maxLength",params:{limit: 320},message:"must NOT have more than 320 characters"}];
return false;
}
}
}
var valid0 = _errs6 === errors;
}
else {
var valid0 = true;
}
if(valid0){
if(data.login !== undefined){
let data3 = data.login;
const _errs8 = errors;
const _errs9 = errors;
let valid1 = false;
const _errs10 = errors;
if(data3 !== null){
const err0 = {instancePath:instancePath+"/login",schemaPath:"#/properties/login/anyOf/0/type",keyword:"type",params:{type: "null"},message:"must be null"};
if(vErrors === null){
vErrors = [err0];
}
else {
vErrors.push(err0);
}
errors++;
}
var _valid0 = _errs10 === errors;
valid1 = valid1 || _valid0;
const _errs12 = errors;
if(errors === _errs12){
if(data3 && typeof data3 == "object" && !Array.isArray(data3)){
let missing1;
if((data3.status === undefined) && (missing1 = "status")){
const err1 = {instancePath:instancePath+"/login",schemaPath:"#/properties/login/anyOf/1/required",keyword:"required",params:{missingProperty: missing1},message:"must have required property '"+missing1+"'"};
if(vErrors === null){
vErrors = [err1];
}
else {
vErrors.push(err1);
}
errors++;
}
else {
const _errs14 = errors;
for(const key1 in data3){
if(!((key1 === "status") || (key1 === "authUrl"))){
const err2 = {instancePath:instancePath+"/login",schemaPath:"#/properties/login/anyOf/1/additionalProperties",keyword:"additionalProperties",params:{additionalProperty: key1},message:"must NOT have additional properties"};
if(vErrors === null){
vErrors = [err2];
}
else {
vErrors.push(err2);
}
errors++;
break;
}
}
if(_errs14 === errors){
if(data3.status !== undefined){
let data4 = data3.status;
const _errs15 = errors;
if(typeof data4 !== "string"){
const err3 = {instancePath:instancePath+"/login/status",schemaPath:"#/properties/login/anyOf/1/properties/status/type",keyword:"type",params:{type: "string"},message:"must be string"};
if(vErrors === null){
vErrors = [err3];
}
else {
vErrors.push(err3);
}
errors++;
}
if(!((((data4 === "pending") || (data4 === "awaiting_code")) || (data4 === "verifying")) || (data4 === "failed"))){
const err4 = {instancePath:instancePath+"/login/status",schemaPath:"#/properties/login/anyOf/1/properties/status/enum",keyword:"enum",params:{allowedValues: schema33.properties.login.anyOf[1].properties.status.enum},message:"must be equal to one of the allowed values"};
if(vErrors === null){
vErrors = [err4];
}
else {
vErrors.push(err4);
}
errors++;
}
var valid2 = _errs15 === errors;
}
else {
var valid2 = true;
}
if(valid2){
if(data3.authUrl !== undefined){
let data5 = data3.authUrl;
const _errs17 = errors;
if(errors === _errs17){
if(errors === _errs17){
if(typeof data5 === "string"){
if(func1(data5) > 4096){
const err5 = {instancePath:instancePath+"/login/authUrl",schemaPath:"#/properties/login/anyOf/1/properties/authUrl/maxLength",keyword:"maxLength",params:{limit: 4096},message:"must NOT have more than 4096 characters"};
if(vErrors === null){
vErrors = [err5];
}
else {
vErrors.push(err5);
}
errors++;
}
else {
if(!pattern4.test(data5)){
const err6 = {instancePath:instancePath+"/login/authUrl",schemaPath:"#/properties/login/anyOf/1/properties/authUrl/pattern",keyword:"pattern",params:{pattern: "^https://(?:claude\\.com|claude\\.ai)(?:[/?#][^\\s\\u0000-\\u001F\\u007F\\\\]*)?$"},message:"must match pattern \""+"^https://(?:claude\\.com|claude\\.ai)(?:[/?#][^\\s\\u0000-\\u001F\\u007F\\\\]*)?$"+"\""};
if(vErrors === null){
vErrors = [err6];
}
else {
vErrors.push(err6);
}
errors++;
}
else {
if(!(formats0.test(data5))){
const err7 = {instancePath:instancePath+"/login/authUrl",schemaPath:"#/properties/login/anyOf/1/properties/authUrl/format",keyword:"format",params:{format: "uri"},message:"must match format \""+"uri"+"\""};
if(vErrors === null){
vErrors = [err7];
}
else {
vErrors.push(err7);
}
errors++;
}
}
}
}
else {
const err8 = {instancePath:instancePath+"/login/authUrl",schemaPath:"#/properties/login/anyOf/1/properties/authUrl/type",keyword:"type",params:{type: "string"},message:"must be string"};
if(vErrors === null){
vErrors = [err8];
}
else {
vErrors.push(err8);
}
errors++;
}
}
}
var valid2 = _errs17 === errors;
}
else {
var valid2 = true;
}
}
}
}
}
else {
const err9 = {instancePath:instancePath+"/login",schemaPath:"#/properties/login/anyOf/1/type",keyword:"type",params:{type: "object"},message:"must be object"};
if(vErrors === null){
vErrors = [err9];
}
else {
vErrors.push(err9);
}
errors++;
}
}
var _valid0 = _errs12 === errors;
valid1 = valid1 || _valid0;
if(!valid1){
const err10 = {instancePath:instancePath+"/login",schemaPath:"#/properties/login/anyOf",keyword:"anyOf",params:{},message:"must match a schema in anyOf"};
if(vErrors === null){
vErrors = [err10];
}
else {
vErrors.push(err10);
}
errors++;
validate21.errors = vErrors;
return false;
}
else {
errors = _errs9;
if(vErrors !== null){
if(_errs9){
vErrors.length = _errs9;
}
else {
vErrors = null;
}
}
}
var valid0 = _errs8 === errors;
}
else {
var valid0 = true;
}
if(valid0){
if(data.error !== undefined){
let data6 = data.error;
const _errs19 = errors;
if(errors === _errs19){
if(typeof data6 === "string"){
if(func1(data6) > 2000){
validate21.errors = [{instancePath:instancePath+"/error",schemaPath:"#/properties/error/maxLength",keyword:"maxLength",params:{limit: 2000},message:"must NOT have more than 2000 characters"}];
return false;
}
else {
if(func1(data6) < 1){
validate21.errors = [{instancePath:instancePath+"/error",schemaPath:"#/properties/error/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
}
}
else {
validate21.errors = [{instancePath:instancePath+"/error",schemaPath:"#/properties/error/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid0 = _errs19 === errors;
}
else {
var valid0 = true;
}
}
}
}
}
}
}
}
else {
validate21.errors = [{instancePath,schemaPath:"#/type",keyword:"type",params:{type: "object"},message:"must be object"}];
return false;
}
}
validate21.errors = vErrors;
return errors === 0;
}
validate21.evaluated = {"props":true,"dynamicProps":false,"dynamicItems":false};

export const codexAccount = validate22;
const schema34 = {"$schema":"https://json-schema.org/draft/2020-12/schema","$id":"https://transcribed-subtitle-extension.local/schemas/codex-account.schema.json","title":"CodexAccount","type":"object","additionalProperties":false,"required":["available","connected","models","login"],"properties":{"available":{"type":"boolean"},"connected":{"type":"boolean"},"models":{"type":"array","items":{"type":"object","additionalProperties":false,"required":["id","name","supportsFastMode"],"properties":{"id":{"type":"string","minLength":1,"maxLength":128,"pattern":"^[A-Za-z0-9][A-Za-z0-9._-]*$"},"name":{"type":"string","minLength":1,"maxLength":256},"supportsFastMode":{"type":"boolean"}}}},"login":{"anyOf":[{"type":"null"},{"type":"object","additionalProperties":false,"required":["status"],"properties":{"status":{"type":"string","enum":["pending","awaiting_authorization","failed"]},"authUrl":{"type":"string","format":"uri","maxLength":4096,"pattern":"^https://(?:auth\\.openai\\.com|chatgpt\\.com)(?:[/?#][^\\s\\u0000-\\u001F\\u007F\\\\]*)?$"}}}]},"error":{"type":"string","minLength":1,"maxLength":2000}}};
const pattern5 = new RegExp("^[A-Za-z0-9][A-Za-z0-9._-]*$", "u");
const pattern6 = new RegExp("^https://(?:auth\\.openai\\.com|chatgpt\\.com)(?:[/?#][^\\s\\u0000-\\u001F\\u007F\\\\]*)?$", "u");

function validate22(data, {instancePath="", parentData, parentDataProperty, rootData=data, dynamicAnchors={}}={}){
/*# sourceURL="https://transcribed-subtitle-extension.local/schemas/codex-account.schema.json" */;
let vErrors = null;
let errors = 0;
const evaluated0 = validate22.evaluated;
if(evaluated0.dynamicProps){
evaluated0.props = undefined;
}
if(evaluated0.dynamicItems){
evaluated0.items = undefined;
}
if(errors === 0){
if(data && typeof data == "object" && !Array.isArray(data)){
let missing0;
if(((((data.available === undefined) && (missing0 = "available")) || ((data.connected === undefined) && (missing0 = "connected"))) || ((data.models === undefined) && (missing0 = "models"))) || ((data.login === undefined) && (missing0 = "login"))){
validate22.errors = [{instancePath,schemaPath:"#/required",keyword:"required",params:{missingProperty: missing0},message:"must have required property '"+missing0+"'"}];
return false;
}
else {
const _errs1 = errors;
for(const key0 in data){
if(!(((((key0 === "available") || (key0 === "connected")) || (key0 === "models")) || (key0 === "login")) || (key0 === "error"))){
validate22.errors = [{instancePath,schemaPath:"#/additionalProperties",keyword:"additionalProperties",params:{additionalProperty: key0},message:"must NOT have additional properties"}];
return false;
break;
}
}
if(_errs1 === errors){
if(data.available !== undefined){
const _errs2 = errors;
if(typeof data.available !== "boolean"){
validate22.errors = [{instancePath:instancePath+"/available",schemaPath:"#/properties/available/type",keyword:"type",params:{type: "boolean"},message:"must be boolean"}];
return false;
}
var valid0 = _errs2 === errors;
}
else {
var valid0 = true;
}
if(valid0){
if(data.connected !== undefined){
const _errs4 = errors;
if(typeof data.connected !== "boolean"){
validate22.errors = [{instancePath:instancePath+"/connected",schemaPath:"#/properties/connected/type",keyword:"type",params:{type: "boolean"},message:"must be boolean"}];
return false;
}
var valid0 = _errs4 === errors;
}
else {
var valid0 = true;
}
if(valid0){
if(data.models !== undefined){
let data2 = data.models;
const _errs6 = errors;
if(errors === _errs6){
if(Array.isArray(data2)){
var valid1 = true;
const len0 = data2.length;
for(let i0=0; i0<len0; i0++){
let data3 = data2[i0];
const _errs8 = errors;
if(errors === _errs8){
if(data3 && typeof data3 == "object" && !Array.isArray(data3)){
let missing1;
if((((data3.id === undefined) && (missing1 = "id")) || ((data3.name === undefined) && (missing1 = "name"))) || ((data3.supportsFastMode === undefined) && (missing1 = "supportsFastMode"))){
validate22.errors = [{instancePath:instancePath+"/models/" + i0,schemaPath:"#/properties/models/items/required",keyword:"required",params:{missingProperty: missing1},message:"must have required property '"+missing1+"'"}];
return false;
}
else {
const _errs10 = errors;
for(const key1 in data3){
if(!(((key1 === "id") || (key1 === "name")) || (key1 === "supportsFastMode"))){
validate22.errors = [{instancePath:instancePath+"/models/" + i0,schemaPath:"#/properties/models/items/additionalProperties",keyword:"additionalProperties",params:{additionalProperty: key1},message:"must NOT have additional properties"}];
return false;
break;
}
}
if(_errs10 === errors){
if(data3.id !== undefined){
let data4 = data3.id;
const _errs11 = errors;
if(errors === _errs11){
if(typeof data4 === "string"){
if(func1(data4) > 128){
validate22.errors = [{instancePath:instancePath+"/models/" + i0+"/id",schemaPath:"#/properties/models/items/properties/id/maxLength",keyword:"maxLength",params:{limit: 128},message:"must NOT have more than 128 characters"}];
return false;
}
else {
if(func1(data4) < 1){
validate22.errors = [{instancePath:instancePath+"/models/" + i0+"/id",schemaPath:"#/properties/models/items/properties/id/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
else {
if(!pattern5.test(data4)){
validate22.errors = [{instancePath:instancePath+"/models/" + i0+"/id",schemaPath:"#/properties/models/items/properties/id/pattern",keyword:"pattern",params:{pattern: "^[A-Za-z0-9][A-Za-z0-9._-]*$"},message:"must match pattern \""+"^[A-Za-z0-9][A-Za-z0-9._-]*$"+"\""}];
return false;
}
}
}
}
else {
validate22.errors = [{instancePath:instancePath+"/models/" + i0+"/id",schemaPath:"#/properties/models/items/properties/id/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid2 = _errs11 === errors;
}
else {
var valid2 = true;
}
if(valid2){
if(data3.name !== undefined){
let data5 = data3.name;
const _errs13 = errors;
if(errors === _errs13){
if(typeof data5 === "string"){
if(func1(data5) > 256){
validate22.errors = [{instancePath:instancePath+"/models/" + i0+"/name",schemaPath:"#/properties/models/items/properties/name/maxLength",keyword:"maxLength",params:{limit: 256},message:"must NOT have more than 256 characters"}];
return false;
}
else {
if(func1(data5) < 1){
validate22.errors = [{instancePath:instancePath+"/models/" + i0+"/name",schemaPath:"#/properties/models/items/properties/name/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
}
}
else {
validate22.errors = [{instancePath:instancePath+"/models/" + i0+"/name",schemaPath:"#/properties/models/items/properties/name/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid2 = _errs13 === errors;
}
else {
var valid2 = true;
}
if(valid2){
if(data3.supportsFastMode !== undefined){
const _errs15 = errors;
if(typeof data3.supportsFastMode !== "boolean"){
validate22.errors = [{instancePath:instancePath+"/models/" + i0+"/supportsFastMode",schemaPath:"#/properties/models/items/properties/supportsFastMode/type",keyword:"type",params:{type: "boolean"},message:"must be boolean"}];
return false;
}
var valid2 = _errs15 === errors;
}
else {
var valid2 = true;
}
}
}
}
}
}
else {
validate22.errors = [{instancePath:instancePath+"/models/" + i0,schemaPath:"#/properties/models/items/type",keyword:"type",params:{type: "object"},message:"must be object"}];
return false;
}
}
var valid1 = _errs8 === errors;
if(!valid1){
break;
}
}
}
else {
validate22.errors = [{instancePath:instancePath+"/models",schemaPath:"#/properties/models/type",keyword:"type",params:{type: "array"},message:"must be array"}];
return false;
}
}
var valid0 = _errs6 === errors;
}
else {
var valid0 = true;
}
if(valid0){
if(data.login !== undefined){
let data7 = data.login;
const _errs17 = errors;
const _errs18 = errors;
let valid3 = false;
const _errs19 = errors;
if(data7 !== null){
const err0 = {instancePath:instancePath+"/login",schemaPath:"#/properties/login/anyOf/0/type",keyword:"type",params:{type: "null"},message:"must be null"};
if(vErrors === null){
vErrors = [err0];
}
else {
vErrors.push(err0);
}
errors++;
}
var _valid0 = _errs19 === errors;
valid3 = valid3 || _valid0;
const _errs21 = errors;
if(errors === _errs21){
if(data7 && typeof data7 == "object" && !Array.isArray(data7)){
let missing2;
if((data7.status === undefined) && (missing2 = "status")){
const err1 = {instancePath:instancePath+"/login",schemaPath:"#/properties/login/anyOf/1/required",keyword:"required",params:{missingProperty: missing2},message:"must have required property '"+missing2+"'"};
if(vErrors === null){
vErrors = [err1];
}
else {
vErrors.push(err1);
}
errors++;
}
else {
const _errs23 = errors;
for(const key2 in data7){
if(!((key2 === "status") || (key2 === "authUrl"))){
const err2 = {instancePath:instancePath+"/login",schemaPath:"#/properties/login/anyOf/1/additionalProperties",keyword:"additionalProperties",params:{additionalProperty: key2},message:"must NOT have additional properties"};
if(vErrors === null){
vErrors = [err2];
}
else {
vErrors.push(err2);
}
errors++;
break;
}
}
if(_errs23 === errors){
if(data7.status !== undefined){
let data8 = data7.status;
const _errs24 = errors;
if(typeof data8 !== "string"){
const err3 = {instancePath:instancePath+"/login/status",schemaPath:"#/properties/login/anyOf/1/properties/status/type",keyword:"type",params:{type: "string"},message:"must be string"};
if(vErrors === null){
vErrors = [err3];
}
else {
vErrors.push(err3);
}
errors++;
}
if(!(((data8 === "pending") || (data8 === "awaiting_authorization")) || (data8 === "failed"))){
const err4 = {instancePath:instancePath+"/login/status",schemaPath:"#/properties/login/anyOf/1/properties/status/enum",keyword:"enum",params:{allowedValues: schema34.properties.login.anyOf[1].properties.status.enum},message:"must be equal to one of the allowed values"};
if(vErrors === null){
vErrors = [err4];
}
else {
vErrors.push(err4);
}
errors++;
}
var valid4 = _errs24 === errors;
}
else {
var valid4 = true;
}
if(valid4){
if(data7.authUrl !== undefined){
let data9 = data7.authUrl;
const _errs26 = errors;
if(errors === _errs26){
if(errors === _errs26){
if(typeof data9 === "string"){
if(func1(data9) > 4096){
const err5 = {instancePath:instancePath+"/login/authUrl",schemaPath:"#/properties/login/anyOf/1/properties/authUrl/maxLength",keyword:"maxLength",params:{limit: 4096},message:"must NOT have more than 4096 characters"};
if(vErrors === null){
vErrors = [err5];
}
else {
vErrors.push(err5);
}
errors++;
}
else {
if(!pattern6.test(data9)){
const err6 = {instancePath:instancePath+"/login/authUrl",schemaPath:"#/properties/login/anyOf/1/properties/authUrl/pattern",keyword:"pattern",params:{pattern: "^https://(?:auth\\.openai\\.com|chatgpt\\.com)(?:[/?#][^\\s\\u0000-\\u001F\\u007F\\\\]*)?$"},message:"must match pattern \""+"^https://(?:auth\\.openai\\.com|chatgpt\\.com)(?:[/?#][^\\s\\u0000-\\u001F\\u007F\\\\]*)?$"+"\""};
if(vErrors === null){
vErrors = [err6];
}
else {
vErrors.push(err6);
}
errors++;
}
else {
if(!(formats0.test(data9))){
const err7 = {instancePath:instancePath+"/login/authUrl",schemaPath:"#/properties/login/anyOf/1/properties/authUrl/format",keyword:"format",params:{format: "uri"},message:"must match format \""+"uri"+"\""};
if(vErrors === null){
vErrors = [err7];
}
else {
vErrors.push(err7);
}
errors++;
}
}
}
}
else {
const err8 = {instancePath:instancePath+"/login/authUrl",schemaPath:"#/properties/login/anyOf/1/properties/authUrl/type",keyword:"type",params:{type: "string"},message:"must be string"};
if(vErrors === null){
vErrors = [err8];
}
else {
vErrors.push(err8);
}
errors++;
}
}
}
var valid4 = _errs26 === errors;
}
else {
var valid4 = true;
}
}
}
}
}
else {
const err9 = {instancePath:instancePath+"/login",schemaPath:"#/properties/login/anyOf/1/type",keyword:"type",params:{type: "object"},message:"must be object"};
if(vErrors === null){
vErrors = [err9];
}
else {
vErrors.push(err9);
}
errors++;
}
}
var _valid0 = _errs21 === errors;
valid3 = valid3 || _valid0;
if(!valid3){
const err10 = {instancePath:instancePath+"/login",schemaPath:"#/properties/login/anyOf",keyword:"anyOf",params:{},message:"must match a schema in anyOf"};
if(vErrors === null){
vErrors = [err10];
}
else {
vErrors.push(err10);
}
errors++;
validate22.errors = vErrors;
return false;
}
else {
errors = _errs18;
if(vErrors !== null){
if(_errs18){
vErrors.length = _errs18;
}
else {
vErrors = null;
}
}
}
var valid0 = _errs17 === errors;
}
else {
var valid0 = true;
}
if(valid0){
if(data.error !== undefined){
let data10 = data.error;
const _errs28 = errors;
if(errors === _errs28){
if(typeof data10 === "string"){
if(func1(data10) > 2000){
validate22.errors = [{instancePath:instancePath+"/error",schemaPath:"#/properties/error/maxLength",keyword:"maxLength",params:{limit: 2000},message:"must NOT have more than 2000 characters"}];
return false;
}
else {
if(func1(data10) < 1){
validate22.errors = [{instancePath:instancePath+"/error",schemaPath:"#/properties/error/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
}
}
else {
validate22.errors = [{instancePath:instancePath+"/error",schemaPath:"#/properties/error/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid0 = _errs28 === errors;
}
else {
var valid0 = true;
}
}
}
}
}
}
}
}
else {
validate22.errors = [{instancePath,schemaPath:"#/type",keyword:"type",params:{type: "object"},message:"must be object"}];
return false;
}
}
validate22.errors = vErrors;
return errors === 0;
}
validate22.evaluated = {"props":true,"dynamicProps":false,"dynamicItems":false};

export const createSubtitleJobRequest = validate23;
const schema35 = {"$schema":"https://json-schema.org/draft/2020-12/schema","$id":"https://transcribed-subtitle-extension.local/schemas/create-subtitle-job-request.schema.json","title":"CreateSubtitleJobRequest","type":"object","additionalProperties":false,"required":["youtubeVideoId","youtubeUrl","sourceLanguage","targetLanguage","includeRomanization","includeTranslation"],"properties":{"youtubeVideoId":{"type":"string","description":"Canonical 11-character YouTube video ID.","pattern":"^[A-Za-z0-9_-]{11}$"},"youtubeUrl":{"type":"string","format":"uri","description":"YouTube watch or Shorts URL captured by the extension for diagnostics and validation."},"videoDurationSeconds":{"type":"integer","minimum":1,"description":"Video duration in seconds."},"sourceLanguage":{"type":"string","enum":["auto","bel","bos","bul","cat","hrv","ces","dan","nld","eng","est","fin","fra","glg","deu","ell","hun","isl","ind","ita","jpn","kan","lav","mkd","msa","mal","nor","pol","por","ron","rus","slk","spa","swe","tur","ukr","vie","hye","aze","ben","yue","fil","kat","guj","hin","kaz","lit","mlt","cmn","mar","nep","ori","fas","srp","slv","swa","tam","tel","afr","ara","asm","ast","mya","hau","heb","jav","kor","kir","ltz","mri","oci","pan","tgk","tha","uzb","cym","amh","lug","ibo","gle","khm","kur","lao","mon","nso","pus","sna","snd","som","urd","wol","xho","yor","zul"],"description":"Learning/source language requested by the extension. Auto detect is source-only. Language choices are defined by languages.json."},"targetLanguage":{"type":"string","description":"Translation/target language requested by the extension. Auto detect is not allowed for target language.","enum":["bel","bos","bul","cat","hrv","ces","dan","nld","eng","est","fin","fra","glg","deu","ell","hun","isl","ind","ita","jpn","kan","lav","mkd","msa","mal","nor","pol","por","ron","rus","slk","spa","swe","tur","ukr","vie","hye","aze","ben","yue","fil","kat","guj","hin","kaz","lit","mlt","cmn","mar","nep","ori","fas","srp","slv","swa","tam","tel","afr","ara","asm","ast","mya","hau","heb","jav","kor","kir","ltz","mri","oci","pan","tgk","tha","uzb","cym","amh","lug","ibo","gle","khm","kur","lao","mon","nso","pus","sna","snd","som","urd","wol","xho","yor","zul"]},"includeRomanization":{"type":"boolean","description":"When true, backend may add cue and token romanization for non-Latin source text. When false, generation skips romanization."},"includeTranslation":{"type":"boolean","description":"When true, backend translates cue text into the selected target language. When false, translatedText remains the source text."},"forceRegenerate":{"type":"boolean","default":false,"description":"When true, rebuild a compatible completed track from the original transcription with fresh tokens, romanization, and translation. Original transcription may be cached; active jobs are still reused. Regeneration uses normal plan minutes."},"aiProvider":{"type":"string","enum":["openai","cerebras","codex","claude","anthropic"],"description":"The selected analysis provider stays pinned for this generation."},"aiModel":{"type":"string","minLength":1,"maxLength":128,"pattern":"^[A-Za-z0-9][A-Za-z0-9._-]*$","description":"Required for Codex (a model from the connected account), Claude subscription and Claude API (models listed below); omitted for other providers."},"aiFastMode":{"type":"boolean","default":false,"description":"Codex fast service tier, or Claude API fast mode on Opus 5.5, Opus 5 and Opus 4.8. Omitted for other providers."}},"allOf":[{"if":{"required":["aiProvider"],"properties":{"aiProvider":{"enum":["codex","claude","anthropic"]}}},"then":{"required":["aiModel"]},"else":{"not":{"required":["aiModel"]}}},{"if":{"required":["aiProvider"],"properties":{"aiProvider":{"enum":["codex","anthropic"]}}},"else":{"properties":{"aiFastMode":{"const":false}}}},{"if":{"required":["aiProvider"],"properties":{"aiProvider":{"const":"claude"}}},"then":{"properties":{"aiModel":{"enum":["opus","sonnet","haiku","claude-fable-5-1","claude-opus-5","claude-opus-4-8","claude-sonnet-5"]}}}},{"if":{"required":["aiProvider"],"properties":{"aiProvider":{"const":"anthropic"}}},"then":{"properties":{"aiModel":{"enum":["claude-opus-5-5","claude-sonnet-5-5","claude-haiku-5-5","claude-fable-5-1","claude-opus-5","claude-opus-4-8","claude-sonnet-5"]}}}},{"if":{"required":["aiProvider","aiFastMode"],"properties":{"aiProvider":{"const":"anthropic"},"aiFastMode":{"const":true}}},"then":{"properties":{"aiModel":{"enum":["claude-opus-5-5","claude-opus-5","claude-opus-4-8"]}}}}]};
const func14 = Object.prototype.hasOwnProperty;
const pattern7 = new RegExp("^[A-Za-z0-9_-]{11}$", "u");

function validate23(data, {instancePath="", parentData, parentDataProperty, rootData=data, dynamicAnchors={}}={}){
/*# sourceURL="https://transcribed-subtitle-extension.local/schemas/create-subtitle-job-request.schema.json" */;
let vErrors = null;
let errors = 0;
const evaluated0 = validate23.evaluated;
if(evaluated0.dynamicProps){
evaluated0.props = undefined;
}
if(evaluated0.dynamicItems){
evaluated0.items = undefined;
}
const _errs1 = errors;
const _errs2 = errors;
let valid1 = true;
const _errs3 = errors;
if(data && typeof data == "object" && !Array.isArray(data)){
let missing0;
if((data.aiProvider === undefined) && (missing0 = "aiProvider")){
const err0 = {};
if(vErrors === null){
vErrors = [err0];
}
else {
vErrors.push(err0);
}
errors++;
}
else {
if(data.aiProvider !== undefined){
let data0 = data.aiProvider;
if(!(((data0 === "codex") || (data0 === "claude")) || (data0 === "anthropic"))){
const err1 = {};
if(vErrors === null){
vErrors = [err1];
}
else {
vErrors.push(err1);
}
errors++;
}
}
}
}
var _valid0 = _errs3 === errors;
errors = _errs2;
if(vErrors !== null){
if(_errs2){
vErrors.length = _errs2;
}
else {
vErrors = null;
}
}
let ifClause0;
if(_valid0){
const _errs5 = errors;
if(data && typeof data == "object" && !Array.isArray(data)){
let missing1;
if((data.aiModel === undefined) && (missing1 = "aiModel")){
validate23.errors = [{instancePath,schemaPath:"#/allOf/0/then/required",keyword:"required",params:{missingProperty: missing1},message:"must have required property '"+missing1+"'"}];
return false;
}
}
var _valid0 = _errs5 === errors;
valid1 = _valid0;
ifClause0 = "then";
}
else {
const _errs6 = errors;
const _errs7 = errors;
const _errs8 = errors;
if(data && typeof data == "object" && !Array.isArray(data)){
let missing2;
if((data.aiModel === undefined) && (missing2 = "aiModel")){
const err2 = {};
if(vErrors === null){
vErrors = [err2];
}
else {
vErrors.push(err2);
}
errors++;
}
}
var valid3 = _errs8 === errors;
if(valid3){
validate23.errors = [{instancePath,schemaPath:"#/allOf/0/else/not",keyword:"not",params:{},message:"must NOT be valid"}];
return false;
}
else {
errors = _errs7;
if(vErrors !== null){
if(_errs7){
vErrors.length = _errs7;
}
else {
vErrors = null;
}
}
}
var _valid0 = _errs6 === errors;
valid1 = _valid0;
ifClause0 = "else";
}
if(!valid1){
const err3 = {instancePath,schemaPath:"#/allOf/0/if",keyword:"if",params:{failingKeyword: ifClause0},message:"must match \""+ifClause0+"\" schema"};
if(vErrors === null){
vErrors = [err3];
}
else {
vErrors.push(err3);
}
errors++;
validate23.errors = vErrors;
return false;
}
var valid0 = _errs1 === errors;
if(valid0){
const _errs9 = errors;
const _errs10 = errors;
let valid4 = true;
const _errs11 = errors;
if(data && typeof data == "object" && !Array.isArray(data)){
let missing3;
if((data.aiProvider === undefined) && (missing3 = "aiProvider")){
const err4 = {};
if(vErrors === null){
vErrors = [err4];
}
else {
vErrors.push(err4);
}
errors++;
}
else {
if(data.aiProvider !== undefined){
let data1 = data.aiProvider;
if(!((data1 === "codex") || (data1 === "anthropic"))){
const err5 = {};
if(vErrors === null){
vErrors = [err5];
}
else {
vErrors.push(err5);
}
errors++;
}
}
}
}
var _valid1 = _errs11 === errors;
errors = _errs10;
if(vErrors !== null){
if(_errs10){
vErrors.length = _errs10;
}
else {
vErrors = null;
}
}
if(!_valid1){
const _errs13 = errors;
if(data && typeof data == "object" && !Array.isArray(data)){
if(data.aiFastMode !== undefined){
if(false !== data.aiFastMode){
validate23.errors = [{instancePath:instancePath+"/aiFastMode",schemaPath:"#/allOf/1/else/properties/aiFastMode/const",keyword:"const",params:{allowedValue: false},message:"must be equal to constant"}];
return false;
}
}
}
var _valid1 = _errs13 === errors;
valid4 = _valid1;
if(valid4){
var props0 = {};
props0.aiFastMode = true;
props0.aiProvider = true;
}
}
if(!valid4){
const err6 = {instancePath,schemaPath:"#/allOf/1/if",keyword:"if",params:{failingKeyword: "else"},message:"must match \"else\" schema"};
if(vErrors === null){
vErrors = [err6];
}
else {
vErrors.push(err6);
}
errors++;
validate23.errors = vErrors;
return false;
}
var valid0 = _errs9 === errors;
if(valid0){
if(props0 !== true){
props0 = props0 || {};
props0.aiProvider = true;
}
const _errs15 = errors;
const _errs16 = errors;
let valid7 = true;
const _errs17 = errors;
if(data && typeof data == "object" && !Array.isArray(data)){
let missing4;
if((data.aiProvider === undefined) && (missing4 = "aiProvider")){
const err7 = {};
if(vErrors === null){
vErrors = [err7];
}
else {
vErrors.push(err7);
}
errors++;
}
else {
if(data.aiProvider !== undefined){
if("claude" !== data.aiProvider){
const err8 = {};
if(vErrors === null){
vErrors = [err8];
}
else {
vErrors.push(err8);
}
errors++;
}
}
}
}
var _valid2 = _errs17 === errors;
errors = _errs16;
if(vErrors !== null){
if(_errs16){
vErrors.length = _errs16;
}
else {
vErrors = null;
}
}
if(_valid2){
const _errs19 = errors;
if(data && typeof data == "object" && !Array.isArray(data)){
if(data.aiModel !== undefined){
let data4 = data.aiModel;
if(!(((((((data4 === "opus") || (data4 === "sonnet")) || (data4 === "haiku")) || (data4 === "claude-fable-5-1")) || (data4 === "claude-opus-5")) || (data4 === "claude-opus-4-8")) || (data4 === "claude-sonnet-5"))){
validate23.errors = [{instancePath:instancePath+"/aiModel",schemaPath:"#/allOf/2/then/properties/aiModel/enum",keyword:"enum",params:{allowedValues: schema35.allOf[2].then.properties.aiModel.enum},message:"must be equal to one of the allowed values"}];
return false;
}
}
}
var _valid2 = _errs19 === errors;
valid7 = _valid2;
if(valid7){
var props1 = {};
props1.aiModel = true;
props1.aiProvider = true;
}
}
if(!valid7){
const err9 = {instancePath,schemaPath:"#/allOf/2/if",keyword:"if",params:{failingKeyword: "then"},message:"must match \"then\" schema"};
if(vErrors === null){
vErrors = [err9];
}
else {
vErrors.push(err9);
}
errors++;
validate23.errors = vErrors;
return false;
}
var valid0 = _errs15 === errors;
if(valid0){
if(props0 !== true && props1 !== undefined){
if(props1 === true){
props0 = true;
}
else {
props0 = props0 || {};
Object.assign(props0, props1);
}
}
const _errs21 = errors;
const _errs22 = errors;
let valid10 = true;
const _errs23 = errors;
if(data && typeof data == "object" && !Array.isArray(data)){
let missing5;
if((data.aiProvider === undefined) && (missing5 = "aiProvider")){
const err10 = {};
if(vErrors === null){
vErrors = [err10];
}
else {
vErrors.push(err10);
}
errors++;
}
else {
if(data.aiProvider !== undefined){
if("anthropic" !== data.aiProvider){
const err11 = {};
if(vErrors === null){
vErrors = [err11];
}
else {
vErrors.push(err11);
}
errors++;
}
}
}
}
var _valid3 = _errs23 === errors;
errors = _errs22;
if(vErrors !== null){
if(_errs22){
vErrors.length = _errs22;
}
else {
vErrors = null;
}
}
if(_valid3){
const _errs25 = errors;
if(data && typeof data == "object" && !Array.isArray(data)){
if(data.aiModel !== undefined){
let data6 = data.aiModel;
if(!(((((((data6 === "claude-opus-5-5") || (data6 === "claude-sonnet-5-5")) || (data6 === "claude-haiku-5-5")) || (data6 === "claude-fable-5-1")) || (data6 === "claude-opus-5")) || (data6 === "claude-opus-4-8")) || (data6 === "claude-sonnet-5"))){
validate23.errors = [{instancePath:instancePath+"/aiModel",schemaPath:"#/allOf/3/then/properties/aiModel/enum",keyword:"enum",params:{allowedValues: schema35.allOf[3].then.properties.aiModel.enum},message:"must be equal to one of the allowed values"}];
return false;
}
}
}
var _valid3 = _errs25 === errors;
valid10 = _valid3;
if(valid10){
var props2 = {};
props2.aiModel = true;
props2.aiProvider = true;
}
}
if(!valid10){
const err12 = {instancePath,schemaPath:"#/allOf/3/if",keyword:"if",params:{failingKeyword: "then"},message:"must match \"then\" schema"};
if(vErrors === null){
vErrors = [err12];
}
else {
vErrors.push(err12);
}
errors++;
validate23.errors = vErrors;
return false;
}
var valid0 = _errs21 === errors;
if(valid0){
if(props0 !== true && props2 !== undefined){
if(props2 === true){
props0 = true;
}
else {
props0 = props0 || {};
Object.assign(props0, props2);
}
}
const _errs27 = errors;
const _errs28 = errors;
let valid13 = true;
const _errs29 = errors;
if(data && typeof data == "object" && !Array.isArray(data)){
let missing6;
if(((data.aiProvider === undefined) && (missing6 = "aiProvider")) || ((data.aiFastMode === undefined) && (missing6 = "aiFastMode"))){
const err13 = {};
if(vErrors === null){
vErrors = [err13];
}
else {
vErrors.push(err13);
}
errors++;
}
else {
if(data.aiProvider !== undefined){
const _errs30 = errors;
if("anthropic" !== data.aiProvider){
const err14 = {};
if(vErrors === null){
vErrors = [err14];
}
else {
vErrors.push(err14);
}
errors++;
}
var valid14 = _errs30 === errors;
}
else {
var valid14 = true;
}
if(valid14){
if(data.aiFastMode !== undefined){
const _errs31 = errors;
if(true !== data.aiFastMode){
const err15 = {};
if(vErrors === null){
vErrors = [err15];
}
else {
vErrors.push(err15);
}
errors++;
}
var valid14 = _errs31 === errors;
}
else {
var valid14 = true;
}
}
}
}
var _valid4 = _errs29 === errors;
errors = _errs28;
if(vErrors !== null){
if(_errs28){
vErrors.length = _errs28;
}
else {
vErrors = null;
}
}
if(_valid4){
const _errs32 = errors;
if(data && typeof data == "object" && !Array.isArray(data)){
if(data.aiModel !== undefined){
let data9 = data.aiModel;
if(!(((data9 === "claude-opus-5-5") || (data9 === "claude-opus-5")) || (data9 === "claude-opus-4-8"))){
validate23.errors = [{instancePath:instancePath+"/aiModel",schemaPath:"#/allOf/4/then/properties/aiModel/enum",keyword:"enum",params:{allowedValues: schema35.allOf[4].then.properties.aiModel.enum},message:"must be equal to one of the allowed values"}];
return false;
}
}
}
var _valid4 = _errs32 === errors;
valid13 = _valid4;
if(valid13){
var props3 = {};
props3.aiModel = true;
props3.aiProvider = true;
props3.aiFastMode = true;
}
}
if(!valid13){
const err16 = {instancePath,schemaPath:"#/allOf/4/if",keyword:"if",params:{failingKeyword: "then"},message:"must match \"then\" schema"};
if(vErrors === null){
vErrors = [err16];
}
else {
vErrors.push(err16);
}
errors++;
validate23.errors = vErrors;
return false;
}
var valid0 = _errs27 === errors;
if(valid0){
if(props0 !== true && props3 !== undefined){
if(props3 === true){
props0 = true;
}
else {
props0 = props0 || {};
Object.assign(props0, props3);
}
}
}
}
}
}
}
if(errors === 0){
if(data && typeof data == "object" && !Array.isArray(data)){
let missing7;
if(((((((data.youtubeVideoId === undefined) && (missing7 = "youtubeVideoId")) || ((data.youtubeUrl === undefined) && (missing7 = "youtubeUrl"))) || ((data.sourceLanguage === undefined) && (missing7 = "sourceLanguage"))) || ((data.targetLanguage === undefined) && (missing7 = "targetLanguage"))) || ((data.includeRomanization === undefined) && (missing7 = "includeRomanization"))) || ((data.includeTranslation === undefined) && (missing7 = "includeTranslation"))){
validate23.errors = [{instancePath,schemaPath:"#/required",keyword:"required",params:{missingProperty: missing7},message:"must have required property '"+missing7+"'"}];
return false;
}
else {
const _errs34 = errors;
for(const key0 in data){
if(!(func14.call(schema35.properties, key0))){
validate23.errors = [{instancePath,schemaPath:"#/additionalProperties",keyword:"additionalProperties",params:{additionalProperty: key0},message:"must NOT have additional properties"}];
return false;
break;
}
}
if(_errs34 === errors){
if(data.youtubeVideoId !== undefined){
let data10 = data.youtubeVideoId;
const _errs35 = errors;
if(errors === _errs35){
if(typeof data10 === "string"){
if(!pattern7.test(data10)){
validate23.errors = [{instancePath:instancePath+"/youtubeVideoId",schemaPath:"#/properties/youtubeVideoId/pattern",keyword:"pattern",params:{pattern: "^[A-Za-z0-9_-]{11}$"},message:"must match pattern \""+"^[A-Za-z0-9_-]{11}$"+"\""}];
return false;
}
}
else {
validate23.errors = [{instancePath:instancePath+"/youtubeVideoId",schemaPath:"#/properties/youtubeVideoId/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid16 = _errs35 === errors;
}
else {
var valid16 = true;
}
if(valid16){
if(data.youtubeUrl !== undefined){
let data11 = data.youtubeUrl;
const _errs37 = errors;
if(errors === _errs37){
if(errors === _errs37){
if(typeof data11 === "string"){
if(!(formats0.test(data11))){
validate23.errors = [{instancePath:instancePath+"/youtubeUrl",schemaPath:"#/properties/youtubeUrl/format",keyword:"format",params:{format: "uri"},message:"must match format \""+"uri"+"\""}];
return false;
}
}
else {
validate23.errors = [{instancePath:instancePath+"/youtubeUrl",schemaPath:"#/properties/youtubeUrl/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
}
var valid16 = _errs37 === errors;
}
else {
var valid16 = true;
}
if(valid16){
if(data.videoDurationSeconds !== undefined){
let data12 = data.videoDurationSeconds;
const _errs39 = errors;
if(!(((typeof data12 == "number") && (!(data12 % 1) && !isNaN(data12))) && (isFinite(data12)))){
validate23.errors = [{instancePath:instancePath+"/videoDurationSeconds",schemaPath:"#/properties/videoDurationSeconds/type",keyword:"type",params:{type: "integer"},message:"must be integer"}];
return false;
}
if(errors === _errs39){
if((typeof data12 == "number") && (isFinite(data12))){
if(data12 < 1 || isNaN(data12)){
validate23.errors = [{instancePath:instancePath+"/videoDurationSeconds",schemaPath:"#/properties/videoDurationSeconds/minimum",keyword:"minimum",params:{comparison: ">=", limit: 1},message:"must be >= 1"}];
return false;
}
}
}
var valid16 = _errs39 === errors;
}
else {
var valid16 = true;
}
if(valid16){
if(data.sourceLanguage !== undefined){
let data13 = data.sourceLanguage;
const _errs41 = errors;
if(typeof data13 !== "string"){
validate23.errors = [{instancePath:instancePath+"/sourceLanguage",schemaPath:"#/properties/sourceLanguage/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
if(!((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((data13 === "auto") || (data13 === "bel")) || (data13 === "bos")) || (data13 === "bul")) || (data13 === "cat")) || (data13 === "hrv")) || (data13 === "ces")) || (data13 === "dan")) || (data13 === "nld")) || (data13 === "eng")) || (data13 === "est")) || (data13 === "fin")) || (data13 === "fra")) || (data13 === "glg")) || (data13 === "deu")) || (data13 === "ell")) || (data13 === "hun")) || (data13 === "isl")) || (data13 === "ind")) || (data13 === "ita")) || (data13 === "jpn")) || (data13 === "kan")) || (data13 === "lav")) || (data13 === "mkd")) || (data13 === "msa")) || (data13 === "mal")) || (data13 === "nor")) || (data13 === "pol")) || (data13 === "por")) || (data13 === "ron")) || (data13 === "rus")) || (data13 === "slk")) || (data13 === "spa")) || (data13 === "swe")) || (data13 === "tur")) || (data13 === "ukr")) || (data13 === "vie")) || (data13 === "hye")) || (data13 === "aze")) || (data13 === "ben")) || (data13 === "yue")) || (data13 === "fil")) || (data13 === "kat")) || (data13 === "guj")) || (data13 === "hin")) || (data13 === "kaz")) || (data13 === "lit")) || (data13 === "mlt")) || (data13 === "cmn")) || (data13 === "mar")) || (data13 === "nep")) || (data13 === "ori")) || (data13 === "fas")) || (data13 === "srp")) || (data13 === "slv")) || (data13 === "swa")) || (data13 === "tam")) || (data13 === "tel")) || (data13 === "afr")) || (data13 === "ara")) || (data13 === "asm")) || (data13 === "ast")) || (data13 === "mya")) || (data13 === "hau")) || (data13 === "heb")) || (data13 === "jav")) || (data13 === "kor")) || (data13 === "kir")) || (data13 === "ltz")) || (data13 === "mri")) || (data13 === "oci")) || (data13 === "pan")) || (data13 === "tgk")) || (data13 === "tha")) || (data13 === "uzb")) || (data13 === "cym")) || (data13 === "amh")) || (data13 === "lug")) || (data13 === "ibo")) || (data13 === "gle")) || (data13 === "khm")) || (data13 === "kur")) || (data13 === "lao")) || (data13 === "mon")) || (data13 === "nso")) || (data13 === "pus")) || (data13 === "sna")) || (data13 === "snd")) || (data13 === "som")) || (data13 === "urd")) || (data13 === "wol")) || (data13 === "xho")) || (data13 === "yor")) || (data13 === "zul"))){
validate23.errors = [{instancePath:instancePath+"/sourceLanguage",schemaPath:"#/properties/sourceLanguage/enum",keyword:"enum",params:{allowedValues: schema35.properties.sourceLanguage.enum},message:"must be equal to one of the allowed values"}];
return false;
}
var valid16 = _errs41 === errors;
}
else {
var valid16 = true;
}
if(valid16){
if(data.targetLanguage !== undefined){
let data14 = data.targetLanguage;
const _errs43 = errors;
if(typeof data14 !== "string"){
validate23.errors = [{instancePath:instancePath+"/targetLanguage",schemaPath:"#/properties/targetLanguage/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
if(!(((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((data14 === "bel") || (data14 === "bos")) || (data14 === "bul")) || (data14 === "cat")) || (data14 === "hrv")) || (data14 === "ces")) || (data14 === "dan")) || (data14 === "nld")) || (data14 === "eng")) || (data14 === "est")) || (data14 === "fin")) || (data14 === "fra")) || (data14 === "glg")) || (data14 === "deu")) || (data14 === "ell")) || (data14 === "hun")) || (data14 === "isl")) || (data14 === "ind")) || (data14 === "ita")) || (data14 === "jpn")) || (data14 === "kan")) || (data14 === "lav")) || (data14 === "mkd")) || (data14 === "msa")) || (data14 === "mal")) || (data14 === "nor")) || (data14 === "pol")) || (data14 === "por")) || (data14 === "ron")) || (data14 === "rus")) || (data14 === "slk")) || (data14 === "spa")) || (data14 === "swe")) || (data14 === "tur")) || (data14 === "ukr")) || (data14 === "vie")) || (data14 === "hye")) || (data14 === "aze")) || (data14 === "ben")) || (data14 === "yue")) || (data14 === "fil")) || (data14 === "kat")) || (data14 === "guj")) || (data14 === "hin")) || (data14 === "kaz")) || (data14 === "lit")) || (data14 === "mlt")) || (data14 === "cmn")) || (data14 === "mar")) || (data14 === "nep")) || (data14 === "ori")) || (data14 === "fas")) || (data14 === "srp")) || (data14 === "slv")) || (data14 === "swa")) || (data14 === "tam")) || (data14 === "tel")) || (data14 === "afr")) || (data14 === "ara")) || (data14 === "asm")) || (data14 === "ast")) || (data14 === "mya")) || (data14 === "hau")) || (data14 === "heb")) || (data14 === "jav")) || (data14 === "kor")) || (data14 === "kir")) || (data14 === "ltz")) || (data14 === "mri")) || (data14 === "oci")) || (data14 === "pan")) || (data14 === "tgk")) || (data14 === "tha")) || (data14 === "uzb")) || (data14 === "cym")) || (data14 === "amh")) || (data14 === "lug")) || (data14 === "ibo")) || (data14 === "gle")) || (data14 === "khm")) || (data14 === "kur")) || (data14 === "lao")) || (data14 === "mon")) || (data14 === "nso")) || (data14 === "pus")) || (data14 === "sna")) || (data14 === "snd")) || (data14 === "som")) || (data14 === "urd")) || (data14 === "wol")) || (data14 === "xho")) || (data14 === "yor")) || (data14 === "zul"))){
validate23.errors = [{instancePath:instancePath+"/targetLanguage",schemaPath:"#/properties/targetLanguage/enum",keyword:"enum",params:{allowedValues: schema35.properties.targetLanguage.enum},message:"must be equal to one of the allowed values"}];
return false;
}
var valid16 = _errs43 === errors;
}
else {
var valid16 = true;
}
if(valid16){
if(data.includeRomanization !== undefined){
const _errs45 = errors;
if(typeof data.includeRomanization !== "boolean"){
validate23.errors = [{instancePath:instancePath+"/includeRomanization",schemaPath:"#/properties/includeRomanization/type",keyword:"type",params:{type: "boolean"},message:"must be boolean"}];
return false;
}
var valid16 = _errs45 === errors;
}
else {
var valid16 = true;
}
if(valid16){
if(data.includeTranslation !== undefined){
const _errs47 = errors;
if(typeof data.includeTranslation !== "boolean"){
validate23.errors = [{instancePath:instancePath+"/includeTranslation",schemaPath:"#/properties/includeTranslation/type",keyword:"type",params:{type: "boolean"},message:"must be boolean"}];
return false;
}
var valid16 = _errs47 === errors;
}
else {
var valid16 = true;
}
if(valid16){
if(data.forceRegenerate !== undefined){
const _errs49 = errors;
if(typeof data.forceRegenerate !== "boolean"){
validate23.errors = [{instancePath:instancePath+"/forceRegenerate",schemaPath:"#/properties/forceRegenerate/type",keyword:"type",params:{type: "boolean"},message:"must be boolean"}];
return false;
}
var valid16 = _errs49 === errors;
}
else {
var valid16 = true;
}
if(valid16){
if(data.aiProvider !== undefined){
let data18 = data.aiProvider;
const _errs51 = errors;
if(typeof data18 !== "string"){
validate23.errors = [{instancePath:instancePath+"/aiProvider",schemaPath:"#/properties/aiProvider/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
if(!(((((data18 === "openai") || (data18 === "cerebras")) || (data18 === "codex")) || (data18 === "claude")) || (data18 === "anthropic"))){
validate23.errors = [{instancePath:instancePath+"/aiProvider",schemaPath:"#/properties/aiProvider/enum",keyword:"enum",params:{allowedValues: schema35.properties.aiProvider.enum},message:"must be equal to one of the allowed values"}];
return false;
}
var valid16 = _errs51 === errors;
}
else {
var valid16 = true;
}
if(valid16){
if(data.aiModel !== undefined){
let data19 = data.aiModel;
const _errs53 = errors;
if(errors === _errs53){
if(typeof data19 === "string"){
if(func1(data19) > 128){
validate23.errors = [{instancePath:instancePath+"/aiModel",schemaPath:"#/properties/aiModel/maxLength",keyword:"maxLength",params:{limit: 128},message:"must NOT have more than 128 characters"}];
return false;
}
else {
if(func1(data19) < 1){
validate23.errors = [{instancePath:instancePath+"/aiModel",schemaPath:"#/properties/aiModel/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
else {
if(!pattern5.test(data19)){
validate23.errors = [{instancePath:instancePath+"/aiModel",schemaPath:"#/properties/aiModel/pattern",keyword:"pattern",params:{pattern: "^[A-Za-z0-9][A-Za-z0-9._-]*$"},message:"must match pattern \""+"^[A-Za-z0-9][A-Za-z0-9._-]*$"+"\""}];
return false;
}
}
}
}
else {
validate23.errors = [{instancePath:instancePath+"/aiModel",schemaPath:"#/properties/aiModel/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid16 = _errs53 === errors;
}
else {
var valid16 = true;
}
if(valid16){
if(data.aiFastMode !== undefined){
const _errs55 = errors;
if(typeof data.aiFastMode !== "boolean"){
validate23.errors = [{instancePath:instancePath+"/aiFastMode",schemaPath:"#/properties/aiFastMode/type",keyword:"type",params:{type: "boolean"},message:"must be boolean"}];
return false;
}
var valid16 = _errs55 === errors;
}
else {
var valid16 = true;
}
}
}
}
}
}
}
}
}
}
}
}
}
}
else {
validate23.errors = [{instancePath,schemaPath:"#/type",keyword:"type",params:{type: "object"},message:"must be object"}];
return false;
}
}
validate23.errors = vErrors;
return errors === 0;
}
validate23.evaluated = {"props":true,"dynamicProps":false,"dynamicItems":false};

export const cue = validate24;
const schema36 = {"$schema":"https://json-schema.org/draft/2020-12/schema","$id":"https://transcribed-subtitle-extension.local/schemas/cue.schema.json","title":"SubtitleCue","type":"object","additionalProperties":false,"required":["cueId","index","startMs","endMs","sourceText","translatedText","tokens"],"properties":{"cueId":{"type":"string","minLength":1},"index":{"type":"integer","minimum":0},"startMs":{"type":"integer","minimum":0},"endMs":{"type":"integer","minimum":1},"sourceText":{"type":"string","minLength":1},"translatedText":{"type":"string","description":"Cue translation. Empty when requested translation is unavailable; source text is retained for translation-disabled or same-language generation."},"romanization":{"type":"string","minLength":1},"tokens":{"type":"array","minItems":1,"items":{"$ref":"token.schema.json"}}}};
const schema37 = {"$schema":"https://json-schema.org/draft/2020-12/schema","$id":"https://transcribed-subtitle-extension.local/schemas/token.schema.json","title":"LearningToken","type":"object","additionalProperties":false,"required":["index","text","normalizedText"],"properties":{"index":{"type":"integer","minimum":0},"text":{"type":"string","minLength":1},"normalizedText":{"type":"string","minLength":1},"lemma":{"type":"string","minLength":1},"root":{"type":"string","minLength":1},"partOfSpeech":{"type":"string","minLength":1},"translation":{"type":"string","minLength":1},"gloss":{"type":"string","minLength":1},"romanization":{"type":"string","minLength":1},"usageNote":{"type":"string","minLength":1}}};

function validate24(data, {instancePath="", parentData, parentDataProperty, rootData=data, dynamicAnchors={}}={}){
/*# sourceURL="https://transcribed-subtitle-extension.local/schemas/cue.schema.json" */;
let vErrors = null;
let errors = 0;
const evaluated0 = validate24.evaluated;
if(evaluated0.dynamicProps){
evaluated0.props = undefined;
}
if(evaluated0.dynamicItems){
evaluated0.items = undefined;
}
if(errors === 0){
if(data && typeof data == "object" && !Array.isArray(data)){
let missing0;
if((((((((data.cueId === undefined) && (missing0 = "cueId")) || ((data.index === undefined) && (missing0 = "index"))) || ((data.startMs === undefined) && (missing0 = "startMs"))) || ((data.endMs === undefined) && (missing0 = "endMs"))) || ((data.sourceText === undefined) && (missing0 = "sourceText"))) || ((data.translatedText === undefined) && (missing0 = "translatedText"))) || ((data.tokens === undefined) && (missing0 = "tokens"))){
validate24.errors = [{instancePath,schemaPath:"#/required",keyword:"required",params:{missingProperty: missing0},message:"must have required property '"+missing0+"'"}];
return false;
}
else {
const _errs1 = errors;
for(const key0 in data){
if(!((((((((key0 === "cueId") || (key0 === "index")) || (key0 === "startMs")) || (key0 === "endMs")) || (key0 === "sourceText")) || (key0 === "translatedText")) || (key0 === "romanization")) || (key0 === "tokens"))){
validate24.errors = [{instancePath,schemaPath:"#/additionalProperties",keyword:"additionalProperties",params:{additionalProperty: key0},message:"must NOT have additional properties"}];
return false;
break;
}
}
if(_errs1 === errors){
if(data.cueId !== undefined){
let data0 = data.cueId;
const _errs2 = errors;
if(errors === _errs2){
if(typeof data0 === "string"){
if(func1(data0) < 1){
validate24.errors = [{instancePath:instancePath+"/cueId",schemaPath:"#/properties/cueId/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
}
else {
validate24.errors = [{instancePath:instancePath+"/cueId",schemaPath:"#/properties/cueId/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid0 = _errs2 === errors;
}
else {
var valid0 = true;
}
if(valid0){
if(data.index !== undefined){
let data1 = data.index;
const _errs4 = errors;
if(!(((typeof data1 == "number") && (!(data1 % 1) && !isNaN(data1))) && (isFinite(data1)))){
validate24.errors = [{instancePath:instancePath+"/index",schemaPath:"#/properties/index/type",keyword:"type",params:{type: "integer"},message:"must be integer"}];
return false;
}
if(errors === _errs4){
if((typeof data1 == "number") && (isFinite(data1))){
if(data1 < 0 || isNaN(data1)){
validate24.errors = [{instancePath:instancePath+"/index",schemaPath:"#/properties/index/minimum",keyword:"minimum",params:{comparison: ">=", limit: 0},message:"must be >= 0"}];
return false;
}
}
}
var valid0 = _errs4 === errors;
}
else {
var valid0 = true;
}
if(valid0){
if(data.startMs !== undefined){
let data2 = data.startMs;
const _errs6 = errors;
if(!(((typeof data2 == "number") && (!(data2 % 1) && !isNaN(data2))) && (isFinite(data2)))){
validate24.errors = [{instancePath:instancePath+"/startMs",schemaPath:"#/properties/startMs/type",keyword:"type",params:{type: "integer"},message:"must be integer"}];
return false;
}
if(errors === _errs6){
if((typeof data2 == "number") && (isFinite(data2))){
if(data2 < 0 || isNaN(data2)){
validate24.errors = [{instancePath:instancePath+"/startMs",schemaPath:"#/properties/startMs/minimum",keyword:"minimum",params:{comparison: ">=", limit: 0},message:"must be >= 0"}];
return false;
}
}
}
var valid0 = _errs6 === errors;
}
else {
var valid0 = true;
}
if(valid0){
if(data.endMs !== undefined){
let data3 = data.endMs;
const _errs8 = errors;
if(!(((typeof data3 == "number") && (!(data3 % 1) && !isNaN(data3))) && (isFinite(data3)))){
validate24.errors = [{instancePath:instancePath+"/endMs",schemaPath:"#/properties/endMs/type",keyword:"type",params:{type: "integer"},message:"must be integer"}];
return false;
}
if(errors === _errs8){
if((typeof data3 == "number") && (isFinite(data3))){
if(data3 < 1 || isNaN(data3)){
validate24.errors = [{instancePath:instancePath+"/endMs",schemaPath:"#/properties/endMs/minimum",keyword:"minimum",params:{comparison: ">=", limit: 1},message:"must be >= 1"}];
return false;
}
}
}
var valid0 = _errs8 === errors;
}
else {
var valid0 = true;
}
if(valid0){
if(data.sourceText !== undefined){
let data4 = data.sourceText;
const _errs10 = errors;
if(errors === _errs10){
if(typeof data4 === "string"){
if(func1(data4) < 1){
validate24.errors = [{instancePath:instancePath+"/sourceText",schemaPath:"#/properties/sourceText/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
}
else {
validate24.errors = [{instancePath:instancePath+"/sourceText",schemaPath:"#/properties/sourceText/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid0 = _errs10 === errors;
}
else {
var valid0 = true;
}
if(valid0){
if(data.translatedText !== undefined){
const _errs12 = errors;
if(typeof data.translatedText !== "string"){
validate24.errors = [{instancePath:instancePath+"/translatedText",schemaPath:"#/properties/translatedText/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
var valid0 = _errs12 === errors;
}
else {
var valid0 = true;
}
if(valid0){
if(data.romanization !== undefined){
let data6 = data.romanization;
const _errs14 = errors;
if(errors === _errs14){
if(typeof data6 === "string"){
if(func1(data6) < 1){
validate24.errors = [{instancePath:instancePath+"/romanization",schemaPath:"#/properties/romanization/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
}
else {
validate24.errors = [{instancePath:instancePath+"/romanization",schemaPath:"#/properties/romanization/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid0 = _errs14 === errors;
}
else {
var valid0 = true;
}
if(valid0){
if(data.tokens !== undefined){
let data7 = data.tokens;
const _errs16 = errors;
if(errors === _errs16){
if(Array.isArray(data7)){
if(data7.length < 1){
validate24.errors = [{instancePath:instancePath+"/tokens",schemaPath:"#/properties/tokens/minItems",keyword:"minItems",params:{limit: 1},message:"must NOT have fewer than 1 items"}];
return false;
}
else {
var valid1 = true;
const len0 = data7.length;
for(let i0=0; i0<len0; i0++){
let data8 = data7[i0];
const _errs18 = errors;
const _errs19 = errors;
if(errors === _errs19){
if(data8 && typeof data8 == "object" && !Array.isArray(data8)){
let missing1;
if((((data8.index === undefined) && (missing1 = "index")) || ((data8.text === undefined) && (missing1 = "text"))) || ((data8.normalizedText === undefined) && (missing1 = "normalizedText"))){
validate24.errors = [{instancePath:instancePath+"/tokens/" + i0,schemaPath:"token.schema.json/required",keyword:"required",params:{missingProperty: missing1},message:"must have required property '"+missing1+"'"}];
return false;
}
else {
const _errs21 = errors;
for(const key1 in data8){
if(!(func14.call(schema37.properties, key1))){
validate24.errors = [{instancePath:instancePath+"/tokens/" + i0,schemaPath:"token.schema.json/additionalProperties",keyword:"additionalProperties",params:{additionalProperty: key1},message:"must NOT have additional properties"}];
return false;
break;
}
}
if(_errs21 === errors){
if(data8.index !== undefined){
let data9 = data8.index;
const _errs22 = errors;
if(!(((typeof data9 == "number") && (!(data9 % 1) && !isNaN(data9))) && (isFinite(data9)))){
validate24.errors = [{instancePath:instancePath+"/tokens/" + i0+"/index",schemaPath:"token.schema.json/properties/index/type",keyword:"type",params:{type: "integer"},message:"must be integer"}];
return false;
}
if(errors === _errs22){
if((typeof data9 == "number") && (isFinite(data9))){
if(data9 < 0 || isNaN(data9)){
validate24.errors = [{instancePath:instancePath+"/tokens/" + i0+"/index",schemaPath:"token.schema.json/properties/index/minimum",keyword:"minimum",params:{comparison: ">=", limit: 0},message:"must be >= 0"}];
return false;
}
}
}
var valid3 = _errs22 === errors;
}
else {
var valid3 = true;
}
if(valid3){
if(data8.text !== undefined){
let data10 = data8.text;
const _errs24 = errors;
if(errors === _errs24){
if(typeof data10 === "string"){
if(func1(data10) < 1){
validate24.errors = [{instancePath:instancePath+"/tokens/" + i0+"/text",schemaPath:"token.schema.json/properties/text/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
}
else {
validate24.errors = [{instancePath:instancePath+"/tokens/" + i0+"/text",schemaPath:"token.schema.json/properties/text/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid3 = _errs24 === errors;
}
else {
var valid3 = true;
}
if(valid3){
if(data8.normalizedText !== undefined){
let data11 = data8.normalizedText;
const _errs26 = errors;
if(errors === _errs26){
if(typeof data11 === "string"){
if(func1(data11) < 1){
validate24.errors = [{instancePath:instancePath+"/tokens/" + i0+"/normalizedText",schemaPath:"token.schema.json/properties/normalizedText/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
}
else {
validate24.errors = [{instancePath:instancePath+"/tokens/" + i0+"/normalizedText",schemaPath:"token.schema.json/properties/normalizedText/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid3 = _errs26 === errors;
}
else {
var valid3 = true;
}
if(valid3){
if(data8.lemma !== undefined){
let data12 = data8.lemma;
const _errs28 = errors;
if(errors === _errs28){
if(typeof data12 === "string"){
if(func1(data12) < 1){
validate24.errors = [{instancePath:instancePath+"/tokens/" + i0+"/lemma",schemaPath:"token.schema.json/properties/lemma/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
}
else {
validate24.errors = [{instancePath:instancePath+"/tokens/" + i0+"/lemma",schemaPath:"token.schema.json/properties/lemma/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid3 = _errs28 === errors;
}
else {
var valid3 = true;
}
if(valid3){
if(data8.root !== undefined){
let data13 = data8.root;
const _errs30 = errors;
if(errors === _errs30){
if(typeof data13 === "string"){
if(func1(data13) < 1){
validate24.errors = [{instancePath:instancePath+"/tokens/" + i0+"/root",schemaPath:"token.schema.json/properties/root/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
}
else {
validate24.errors = [{instancePath:instancePath+"/tokens/" + i0+"/root",schemaPath:"token.schema.json/properties/root/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid3 = _errs30 === errors;
}
else {
var valid3 = true;
}
if(valid3){
if(data8.partOfSpeech !== undefined){
let data14 = data8.partOfSpeech;
const _errs32 = errors;
if(errors === _errs32){
if(typeof data14 === "string"){
if(func1(data14) < 1){
validate24.errors = [{instancePath:instancePath+"/tokens/" + i0+"/partOfSpeech",schemaPath:"token.schema.json/properties/partOfSpeech/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
}
else {
validate24.errors = [{instancePath:instancePath+"/tokens/" + i0+"/partOfSpeech",schemaPath:"token.schema.json/properties/partOfSpeech/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid3 = _errs32 === errors;
}
else {
var valid3 = true;
}
if(valid3){
if(data8.translation !== undefined){
let data15 = data8.translation;
const _errs34 = errors;
if(errors === _errs34){
if(typeof data15 === "string"){
if(func1(data15) < 1){
validate24.errors = [{instancePath:instancePath+"/tokens/" + i0+"/translation",schemaPath:"token.schema.json/properties/translation/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
}
else {
validate24.errors = [{instancePath:instancePath+"/tokens/" + i0+"/translation",schemaPath:"token.schema.json/properties/translation/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid3 = _errs34 === errors;
}
else {
var valid3 = true;
}
if(valid3){
if(data8.gloss !== undefined){
let data16 = data8.gloss;
const _errs36 = errors;
if(errors === _errs36){
if(typeof data16 === "string"){
if(func1(data16) < 1){
validate24.errors = [{instancePath:instancePath+"/tokens/" + i0+"/gloss",schemaPath:"token.schema.json/properties/gloss/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
}
else {
validate24.errors = [{instancePath:instancePath+"/tokens/" + i0+"/gloss",schemaPath:"token.schema.json/properties/gloss/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid3 = _errs36 === errors;
}
else {
var valid3 = true;
}
if(valid3){
if(data8.romanization !== undefined){
let data17 = data8.romanization;
const _errs38 = errors;
if(errors === _errs38){
if(typeof data17 === "string"){
if(func1(data17) < 1){
validate24.errors = [{instancePath:instancePath+"/tokens/" + i0+"/romanization",schemaPath:"token.schema.json/properties/romanization/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
}
else {
validate24.errors = [{instancePath:instancePath+"/tokens/" + i0+"/romanization",schemaPath:"token.schema.json/properties/romanization/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid3 = _errs38 === errors;
}
else {
var valid3 = true;
}
if(valid3){
if(data8.usageNote !== undefined){
let data18 = data8.usageNote;
const _errs40 = errors;
if(errors === _errs40){
if(typeof data18 === "string"){
if(func1(data18) < 1){
validate24.errors = [{instancePath:instancePath+"/tokens/" + i0+"/usageNote",schemaPath:"token.schema.json/properties/usageNote/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
}
else {
validate24.errors = [{instancePath:instancePath+"/tokens/" + i0+"/usageNote",schemaPath:"token.schema.json/properties/usageNote/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid3 = _errs40 === errors;
}
else {
var valid3 = true;
}
}
}
}
}
}
}
}
}
}
}
}
}
else {
validate24.errors = [{instancePath:instancePath+"/tokens/" + i0,schemaPath:"token.schema.json/type",keyword:"type",params:{type: "object"},message:"must be object"}];
return false;
}
}
var valid1 = _errs18 === errors;
if(!valid1){
break;
}
}
}
}
else {
validate24.errors = [{instancePath:instancePath+"/tokens",schemaPath:"#/properties/tokens/type",keyword:"type",params:{type: "array"},message:"must be array"}];
return false;
}
}
var valid0 = _errs16 === errors;
}
else {
var valid0 = true;
}
}
}
}
}
}
}
}
}
}
}
else {
validate24.errors = [{instancePath,schemaPath:"#/type",keyword:"type",params:{type: "object"},message:"must be object"}];
return false;
}
}
validate24.errors = vErrors;
return errors === 0;
}
validate24.evaluated = {"props":true,"dynamicProps":false,"dynamicItems":false};

export const errorObject = validate25;

function validate25(data, {instancePath="", parentData, parentDataProperty, rootData=data, dynamicAnchors={}}={}){
/*# sourceURL="https://transcribed-subtitle-extension.local/schemas/error-object.schema.json" */;
let vErrors = null;
let errors = 0;
const evaluated0 = validate25.evaluated;
if(evaluated0.dynamicProps){
evaluated0.props = undefined;
}
if(evaluated0.dynamicItems){
evaluated0.items = undefined;
}
if(errors === 0){
if(data && typeof data == "object" && !Array.isArray(data)){
let missing0;
if(((data.code === undefined) && (missing0 = "code")) || ((data.message === undefined) && (missing0 = "message"))){
validate25.errors = [{instancePath,schemaPath:"#/required",keyword:"required",params:{missingProperty: missing0},message:"must have required property '"+missing0+"'"}];
return false;
}
else {
const _errs1 = errors;
for(const key0 in data){
if(!(((key0 === "code") || (key0 === "message")) || (key0 === "details"))){
validate25.errors = [{instancePath,schemaPath:"#/additionalProperties",keyword:"additionalProperties",params:{additionalProperty: key0},message:"must NOT have additional properties"}];
return false;
break;
}
}
if(_errs1 === errors){
if(data.code !== undefined){
let data0 = data.code;
const _errs2 = errors;
if(typeof data0 !== "string"){
validate25.errors = [{instancePath:instancePath+"/code",schemaPath:"#/properties/code/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
if(!((((((((((((((((((((data0 === "validation_failed") || (data0 === "unauthorized")) || (data0 === "unsupported_video")) || (data0 === "audio_unavailable")) || (data0 === "audio_acquisition_failed")) || (data0 === "queue_publication_failed")) || (data0 === "generation_cancelled")) || (data0 === "generation_not_cancellable")) || (data0 === "transcription_failed")) || (data0 === "enrichment_failed")) || (data0 === "rate_limited")) || (data0 === "not_found")) || (data0 === "expired")) || (data0 === "lyrics_correction_in_progress")) || (data0 === "lyrics_incomplete")) || (data0 === "lyrics_do_not_match")) || (data0 === "lyrics_correction_failed")) || (data0 === "internal_error")) || (data0 === "provider_not_configured")) || (data0 === "provider_unavailable"))){
validate25.errors = [{instancePath:instancePath+"/code",schemaPath:"#/properties/code/enum",keyword:"enum",params:{allowedValues: schema32.properties.code.enum},message:"must be equal to one of the allowed values"}];
return false;
}
var valid0 = _errs2 === errors;
}
else {
var valid0 = true;
}
if(valid0){
if(data.message !== undefined){
let data1 = data.message;
const _errs4 = errors;
if(errors === _errs4){
if(typeof data1 === "string"){
if(func1(data1) < 1){
validate25.errors = [{instancePath:instancePath+"/message",schemaPath:"#/properties/message/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
}
else {
validate25.errors = [{instancePath:instancePath+"/message",schemaPath:"#/properties/message/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid0 = _errs4 === errors;
}
else {
var valid0 = true;
}
if(valid0){
if(data.details !== undefined){
let data2 = data.details;
const _errs6 = errors;
if(errors === _errs6){
if(data2 && typeof data2 == "object" && !Array.isArray(data2)){
if(data2.reason !== undefined){
let data3 = data2.reason;
if(typeof data3 !== "string"){
validate25.errors = [{instancePath:instancePath+"/details/reason",schemaPath:"#/properties/details/properties/reason/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
if(!(data3 === "stale_track")){
validate25.errors = [{instancePath:instancePath+"/details/reason",schemaPath:"#/properties/details/properties/reason/enum",keyword:"enum",params:{allowedValues: schema32.properties.details.properties.reason.enum},message:"must be equal to one of the allowed values"}];
return false;
}
}
}
else {
validate25.errors = [{instancePath:instancePath+"/details",schemaPath:"#/properties/details/type",keyword:"type",params:{type: "object"},message:"must be object"}];
return false;
}
}
var valid0 = _errs6 === errors;
}
else {
var valid0 = true;
}
}
}
}
}
}
else {
validate25.errors = [{instancePath,schemaPath:"#/type",keyword:"type",params:{type: "object"},message:"must be object"}];
return false;
}
}
validate25.errors = vErrors;
return errors === 0;
}
validate25.evaluated = {"props":true,"dynamicProps":false,"dynamicItems":false};

export const instanceSettings = validate26;
const schema39 = {"$schema":"https://json-schema.org/draft/2020-12/schema","$id":"https://transcribed-subtitle-extension.local/schemas/instance-settings.schema.json","title":"InstanceSettings","type":"object","additionalProperties":false,"properties":{"providers":{"type":"object","additionalProperties":false,"properties":{"openai":{"type":"object","additionalProperties":false,"required":["configured","model"],"properties":{"configured":{"type":"boolean"},"model":{"type":"string","minLength":1}}},"cerebras":{"type":"object","additionalProperties":false,"required":["configured","model"],"properties":{"configured":{"type":"boolean"},"model":{"type":"string","minLength":1}}},"elevenlabs":{"type":"object","additionalProperties":false,"required":["configured","model"],"properties":{"configured":{"type":"boolean"},"model":{"type":"string","minLength":1}}},"claude":{"type":"object","additionalProperties":false,"required":["configured","available"],"properties":{"configured":{"type":"boolean"},"available":{"type":"boolean"}}},"anthropic":{"type":"object","additionalProperties":false,"required":["configured"],"properties":{"configured":{"type":"boolean"}}}},"required":["openai","cerebras","elevenlabs","claude","anthropic"]},"retentionDays":{"type":["integer","null"],"minimum":1,"maximum":36500}},"required":["providers","retentionDays"]};

function validate26(data, {instancePath="", parentData, parentDataProperty, rootData=data, dynamicAnchors={}}={}){
/*# sourceURL="https://transcribed-subtitle-extension.local/schemas/instance-settings.schema.json" */;
let vErrors = null;
let errors = 0;
const evaluated0 = validate26.evaluated;
if(evaluated0.dynamicProps){
evaluated0.props = undefined;
}
if(evaluated0.dynamicItems){
evaluated0.items = undefined;
}
if(errors === 0){
if(data && typeof data == "object" && !Array.isArray(data)){
let missing0;
if(((data.providers === undefined) && (missing0 = "providers")) || ((data.retentionDays === undefined) && (missing0 = "retentionDays"))){
validate26.errors = [{instancePath,schemaPath:"#/required",keyword:"required",params:{missingProperty: missing0},message:"must have required property '"+missing0+"'"}];
return false;
}
else {
const _errs1 = errors;
for(const key0 in data){
if(!((key0 === "providers") || (key0 === "retentionDays"))){
validate26.errors = [{instancePath,schemaPath:"#/additionalProperties",keyword:"additionalProperties",params:{additionalProperty: key0},message:"must NOT have additional properties"}];
return false;
break;
}
}
if(_errs1 === errors){
if(data.providers !== undefined){
let data0 = data.providers;
const _errs2 = errors;
if(errors === _errs2){
if(data0 && typeof data0 == "object" && !Array.isArray(data0)){
let missing1;
if((((((data0.openai === undefined) && (missing1 = "openai")) || ((data0.cerebras === undefined) && (missing1 = "cerebras"))) || ((data0.elevenlabs === undefined) && (missing1 = "elevenlabs"))) || ((data0.claude === undefined) && (missing1 = "claude"))) || ((data0.anthropic === undefined) && (missing1 = "anthropic"))){
validate26.errors = [{instancePath:instancePath+"/providers",schemaPath:"#/properties/providers/required",keyword:"required",params:{missingProperty: missing1},message:"must have required property '"+missing1+"'"}];
return false;
}
else {
const _errs4 = errors;
for(const key1 in data0){
if(!(((((key1 === "openai") || (key1 === "cerebras")) || (key1 === "elevenlabs")) || (key1 === "claude")) || (key1 === "anthropic"))){
validate26.errors = [{instancePath:instancePath+"/providers",schemaPath:"#/properties/providers/additionalProperties",keyword:"additionalProperties",params:{additionalProperty: key1},message:"must NOT have additional properties"}];
return false;
break;
}
}
if(_errs4 === errors){
if(data0.openai !== undefined){
let data1 = data0.openai;
const _errs5 = errors;
if(errors === _errs5){
if(data1 && typeof data1 == "object" && !Array.isArray(data1)){
let missing2;
if(((data1.configured === undefined) && (missing2 = "configured")) || ((data1.model === undefined) && (missing2 = "model"))){
validate26.errors = [{instancePath:instancePath+"/providers/openai",schemaPath:"#/properties/providers/properties/openai/required",keyword:"required",params:{missingProperty: missing2},message:"must have required property '"+missing2+"'"}];
return false;
}
else {
const _errs7 = errors;
for(const key2 in data1){
if(!((key2 === "configured") || (key2 === "model"))){
validate26.errors = [{instancePath:instancePath+"/providers/openai",schemaPath:"#/properties/providers/properties/openai/additionalProperties",keyword:"additionalProperties",params:{additionalProperty: key2},message:"must NOT have additional properties"}];
return false;
break;
}
}
if(_errs7 === errors){
if(data1.configured !== undefined){
const _errs8 = errors;
if(typeof data1.configured !== "boolean"){
validate26.errors = [{instancePath:instancePath+"/providers/openai/configured",schemaPath:"#/properties/providers/properties/openai/properties/configured/type",keyword:"type",params:{type: "boolean"},message:"must be boolean"}];
return false;
}
var valid2 = _errs8 === errors;
}
else {
var valid2 = true;
}
if(valid2){
if(data1.model !== undefined){
let data3 = data1.model;
const _errs10 = errors;
if(errors === _errs10){
if(typeof data3 === "string"){
if(func1(data3) < 1){
validate26.errors = [{instancePath:instancePath+"/providers/openai/model",schemaPath:"#/properties/providers/properties/openai/properties/model/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
}
else {
validate26.errors = [{instancePath:instancePath+"/providers/openai/model",schemaPath:"#/properties/providers/properties/openai/properties/model/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid2 = _errs10 === errors;
}
else {
var valid2 = true;
}
}
}
}
}
else {
validate26.errors = [{instancePath:instancePath+"/providers/openai",schemaPath:"#/properties/providers/properties/openai/type",keyword:"type",params:{type: "object"},message:"must be object"}];
return false;
}
}
var valid1 = _errs5 === errors;
}
else {
var valid1 = true;
}
if(valid1){
if(data0.cerebras !== undefined){
let data4 = data0.cerebras;
const _errs12 = errors;
if(errors === _errs12){
if(data4 && typeof data4 == "object" && !Array.isArray(data4)){
let missing3;
if(((data4.configured === undefined) && (missing3 = "configured")) || ((data4.model === undefined) && (missing3 = "model"))){
validate26.errors = [{instancePath:instancePath+"/providers/cerebras",schemaPath:"#/properties/providers/properties/cerebras/required",keyword:"required",params:{missingProperty: missing3},message:"must have required property '"+missing3+"'"}];
return false;
}
else {
const _errs14 = errors;
for(const key3 in data4){
if(!((key3 === "configured") || (key3 === "model"))){
validate26.errors = [{instancePath:instancePath+"/providers/cerebras",schemaPath:"#/properties/providers/properties/cerebras/additionalProperties",keyword:"additionalProperties",params:{additionalProperty: key3},message:"must NOT have additional properties"}];
return false;
break;
}
}
if(_errs14 === errors){
if(data4.configured !== undefined){
const _errs15 = errors;
if(typeof data4.configured !== "boolean"){
validate26.errors = [{instancePath:instancePath+"/providers/cerebras/configured",schemaPath:"#/properties/providers/properties/cerebras/properties/configured/type",keyword:"type",params:{type: "boolean"},message:"must be boolean"}];
return false;
}
var valid3 = _errs15 === errors;
}
else {
var valid3 = true;
}
if(valid3){
if(data4.model !== undefined){
let data6 = data4.model;
const _errs17 = errors;
if(errors === _errs17){
if(typeof data6 === "string"){
if(func1(data6) < 1){
validate26.errors = [{instancePath:instancePath+"/providers/cerebras/model",schemaPath:"#/properties/providers/properties/cerebras/properties/model/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
}
else {
validate26.errors = [{instancePath:instancePath+"/providers/cerebras/model",schemaPath:"#/properties/providers/properties/cerebras/properties/model/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid3 = _errs17 === errors;
}
else {
var valid3 = true;
}
}
}
}
}
else {
validate26.errors = [{instancePath:instancePath+"/providers/cerebras",schemaPath:"#/properties/providers/properties/cerebras/type",keyword:"type",params:{type: "object"},message:"must be object"}];
return false;
}
}
var valid1 = _errs12 === errors;
}
else {
var valid1 = true;
}
if(valid1){
if(data0.elevenlabs !== undefined){
let data7 = data0.elevenlabs;
const _errs19 = errors;
if(errors === _errs19){
if(data7 && typeof data7 == "object" && !Array.isArray(data7)){
let missing4;
if(((data7.configured === undefined) && (missing4 = "configured")) || ((data7.model === undefined) && (missing4 = "model"))){
validate26.errors = [{instancePath:instancePath+"/providers/elevenlabs",schemaPath:"#/properties/providers/properties/elevenlabs/required",keyword:"required",params:{missingProperty: missing4},message:"must have required property '"+missing4+"'"}];
return false;
}
else {
const _errs21 = errors;
for(const key4 in data7){
if(!((key4 === "configured") || (key4 === "model"))){
validate26.errors = [{instancePath:instancePath+"/providers/elevenlabs",schemaPath:"#/properties/providers/properties/elevenlabs/additionalProperties",keyword:"additionalProperties",params:{additionalProperty: key4},message:"must NOT have additional properties"}];
return false;
break;
}
}
if(_errs21 === errors){
if(data7.configured !== undefined){
const _errs22 = errors;
if(typeof data7.configured !== "boolean"){
validate26.errors = [{instancePath:instancePath+"/providers/elevenlabs/configured",schemaPath:"#/properties/providers/properties/elevenlabs/properties/configured/type",keyword:"type",params:{type: "boolean"},message:"must be boolean"}];
return false;
}
var valid4 = _errs22 === errors;
}
else {
var valid4 = true;
}
if(valid4){
if(data7.model !== undefined){
let data9 = data7.model;
const _errs24 = errors;
if(errors === _errs24){
if(typeof data9 === "string"){
if(func1(data9) < 1){
validate26.errors = [{instancePath:instancePath+"/providers/elevenlabs/model",schemaPath:"#/properties/providers/properties/elevenlabs/properties/model/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
}
else {
validate26.errors = [{instancePath:instancePath+"/providers/elevenlabs/model",schemaPath:"#/properties/providers/properties/elevenlabs/properties/model/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid4 = _errs24 === errors;
}
else {
var valid4 = true;
}
}
}
}
}
else {
validate26.errors = [{instancePath:instancePath+"/providers/elevenlabs",schemaPath:"#/properties/providers/properties/elevenlabs/type",keyword:"type",params:{type: "object"},message:"must be object"}];
return false;
}
}
var valid1 = _errs19 === errors;
}
else {
var valid1 = true;
}
if(valid1){
if(data0.claude !== undefined){
let data10 = data0.claude;
const _errs26 = errors;
if(errors === _errs26){
if(data10 && typeof data10 == "object" && !Array.isArray(data10)){
let missing5;
if(((data10.configured === undefined) && (missing5 = "configured")) || ((data10.available === undefined) && (missing5 = "available"))){
validate26.errors = [{instancePath:instancePath+"/providers/claude",schemaPath:"#/properties/providers/properties/claude/required",keyword:"required",params:{missingProperty: missing5},message:"must have required property '"+missing5+"'"}];
return false;
}
else {
const _errs28 = errors;
for(const key5 in data10){
if(!((key5 === "configured") || (key5 === "available"))){
validate26.errors = [{instancePath:instancePath+"/providers/claude",schemaPath:"#/properties/providers/properties/claude/additionalProperties",keyword:"additionalProperties",params:{additionalProperty: key5},message:"must NOT have additional properties"}];
return false;
break;
}
}
if(_errs28 === errors){
if(data10.configured !== undefined){
const _errs29 = errors;
if(typeof data10.configured !== "boolean"){
validate26.errors = [{instancePath:instancePath+"/providers/claude/configured",schemaPath:"#/properties/providers/properties/claude/properties/configured/type",keyword:"type",params:{type: "boolean"},message:"must be boolean"}];
return false;
}
var valid5 = _errs29 === errors;
}
else {
var valid5 = true;
}
if(valid5){
if(data10.available !== undefined){
const _errs31 = errors;
if(typeof data10.available !== "boolean"){
validate26.errors = [{instancePath:instancePath+"/providers/claude/available",schemaPath:"#/properties/providers/properties/claude/properties/available/type",keyword:"type",params:{type: "boolean"},message:"must be boolean"}];
return false;
}
var valid5 = _errs31 === errors;
}
else {
var valid5 = true;
}
}
}
}
}
else {
validate26.errors = [{instancePath:instancePath+"/providers/claude",schemaPath:"#/properties/providers/properties/claude/type",keyword:"type",params:{type: "object"},message:"must be object"}];
return false;
}
}
var valid1 = _errs26 === errors;
}
else {
var valid1 = true;
}
if(valid1){
if(data0.anthropic !== undefined){
let data13 = data0.anthropic;
const _errs33 = errors;
if(errors === _errs33){
if(data13 && typeof data13 == "object" && !Array.isArray(data13)){
let missing6;
if((data13.configured === undefined) && (missing6 = "configured")){
validate26.errors = [{instancePath:instancePath+"/providers/anthropic",schemaPath:"#/properties/providers/properties/anthropic/required",keyword:"required",params:{missingProperty: missing6},message:"must have required property '"+missing6+"'"}];
return false;
}
else {
const _errs35 = errors;
for(const key6 in data13){
if(!(key6 === "configured")){
validate26.errors = [{instancePath:instancePath+"/providers/anthropic",schemaPath:"#/properties/providers/properties/anthropic/additionalProperties",keyword:"additionalProperties",params:{additionalProperty: key6},message:"must NOT have additional properties"}];
return false;
break;
}
}
if(_errs35 === errors){
if(data13.configured !== undefined){
if(typeof data13.configured !== "boolean"){
validate26.errors = [{instancePath:instancePath+"/providers/anthropic/configured",schemaPath:"#/properties/providers/properties/anthropic/properties/configured/type",keyword:"type",params:{type: "boolean"},message:"must be boolean"}];
return false;
}
}
}
}
}
else {
validate26.errors = [{instancePath:instancePath+"/providers/anthropic",schemaPath:"#/properties/providers/properties/anthropic/type",keyword:"type",params:{type: "object"},message:"must be object"}];
return false;
}
}
var valid1 = _errs33 === errors;
}
else {
var valid1 = true;
}
}
}
}
}
}
}
}
else {
validate26.errors = [{instancePath:instancePath+"/providers",schemaPath:"#/properties/providers/type",keyword:"type",params:{type: "object"},message:"must be object"}];
return false;
}
}
var valid0 = _errs2 === errors;
}
else {
var valid0 = true;
}
if(valid0){
if(data.retentionDays !== undefined){
let data15 = data.retentionDays;
const _errs38 = errors;
if((!(((typeof data15 == "number") && (!(data15 % 1) && !isNaN(data15))) && (isFinite(data15)))) && (data15 !== null)){
validate26.errors = [{instancePath:instancePath+"/retentionDays",schemaPath:"#/properties/retentionDays/type",keyword:"type",params:{type: schema39.properties.retentionDays.type},message:"must be integer,null"}];
return false;
}
if(errors === _errs38){
if((typeof data15 == "number") && (isFinite(data15))){
if(data15 > 36500 || isNaN(data15)){
validate26.errors = [{instancePath:instancePath+"/retentionDays",schemaPath:"#/properties/retentionDays/maximum",keyword:"maximum",params:{comparison: "<=", limit: 36500},message:"must be <= 36500"}];
return false;
}
else {
if(data15 < 1 || isNaN(data15)){
validate26.errors = [{instancePath:instancePath+"/retentionDays",schemaPath:"#/properties/retentionDays/minimum",keyword:"minimum",params:{comparison: ">=", limit: 1},message:"must be >= 1"}];
return false;
}
}
}
}
var valid0 = _errs38 === errors;
}
else {
var valid0 = true;
}
}
}
}
}
else {
validate26.errors = [{instancePath,schemaPath:"#/type",keyword:"type",params:{type: "object"},message:"must be object"}];
return false;
}
}
validate26.errors = vErrors;
return errors === 0;
}
validate26.evaluated = {"props":true,"dynamicProps":false,"dynamicItems":false};

export const jobResponse = validate27;
const schema40 = {"$schema":"https://json-schema.org/draft/2020-12/schema","$id":"https://transcribed-subtitle-extension.local/schemas/job-response.schema.json","title":"JobResponse","type":"object","additionalProperties":false,"required":["jobId","youtubeVideoId","sourceLanguage","targetLanguage","includeRomanization","includeTranslation","status","stage","progressPercent","createdAt","updatedAt","aiProvider","aiModel"],"properties":{"jobId":{"type":"string","format":"uuid"},"youtubeVideoId":{"type":"string","pattern":"^[A-Za-z0-9_-]{11}$"},"videoDurationSeconds":{"type":"integer","minimum":1,"description":"Video duration in seconds."},"sourceLanguage":{"type":"string","enum":["auto","bel","bos","bul","cat","hrv","ces","dan","nld","eng","est","fin","fra","glg","deu","ell","hun","isl","ind","ita","jpn","kan","lav","mkd","msa","mal","nor","pol","por","ron","rus","slk","spa","swe","tur","ukr","vie","hye","aze","ben","yue","fil","kat","guj","hin","kaz","lit","mlt","cmn","mar","nep","ori","fas","srp","slv","swa","tam","tel","afr","ara","asm","ast","mya","hau","heb","jav","kor","kir","ltz","mri","oci","pan","tgk","tha","uzb","cym","amh","lug","ibo","gle","khm","kur","lao","mon","nso","pus","sna","snd","som","urd","wol","xho","yor","zul"]},"targetLanguage":{"type":"string","enum":["bel","bos","bul","cat","hrv","ces","dan","nld","eng","est","fin","fra","glg","deu","ell","hun","isl","ind","ita","jpn","kan","lav","mkd","msa","mal","nor","pol","por","ron","rus","slk","spa","swe","tur","ukr","vie","hye","aze","ben","yue","fil","kat","guj","hin","kaz","lit","mlt","cmn","mar","nep","ori","fas","srp","slv","swa","tam","tel","afr","ara","asm","ast","mya","hau","heb","jav","kor","kir","ltz","mri","oci","pan","tgk","tha","uzb","cym","amh","lug","ibo","gle","khm","kur","lao","mon","nso","pus","sna","snd","som","urd","wol","xho","yor","zul"]},"includeRomanization":{"type":"boolean","description":"Whether the request asked the backend to add romanization where available."},"includeTranslation":{"type":"boolean","description":"Whether the request asked the backend to translate cue text."},"status":{"type":"string","enum":["queued","running","completed","failed","cancelled"]},"stage":{"type":"string","enum":["preparing","acquiring-audio","optimizing-audio","transcribing","tokenizing","romanizing","translating","finalizing"]},"progressPercent":{"type":"integer","minimum":0,"maximum":100},"track":{"$ref":"track-response.schema.json"},"createdAt":{"type":"string","format":"date-time"},"updatedAt":{"type":"string","format":"date-time"},"expiresAt":{"type":["string","null"],"format":"date-time"},"detectedSourceLanguage":{"type":"string","enum":["bel","bos","bul","cat","hrv","ces","dan","nld","eng","est","fin","fra","glg","deu","ell","hun","isl","ind","ita","jpn","kan","lav","mkd","msa","mal","nor","pol","por","ron","rus","slk","spa","swe","tur","ukr","vie","hye","aze","ben","yue","fil","kat","guj","hin","kaz","lit","mlt","cmn","mar","nep","ori","fas","srp","slv","swa","tam","tel","afr","ara","asm","ast","mya","hau","heb","jav","kor","kir","ltz","mri","oci","pan","tgk","tha","uzb","cym","amh","lug","ibo","gle","khm","kur","lao","mon","nso","pus","sna","snd","som","urd","wol","xho","yor","zul"],"description":"Provider-detected source language when sourceLanguage was auto and detection produced a catalog language."},"message":{"type":"string","minLength":1},"errorCode":{"$ref":"error-object.schema.json#/properties/code"},"aiProvider":{"type":"string","enum":["openai","cerebras","codex","claude","anthropic"],"description":"The selected analysis provider stays pinned for this generation."},"aiModel":{"type":"string","minLength":1,"maxLength":128,"description":"Exact text model saved on this job."},"partialTrack":{"$ref":"partial-track-response.schema.json","description":"Growing partial track. Omitted when sinceRevision matches its revision."},"aiFastMode":{"type":"boolean","default":false,"description":"Pinned Codex fast mode. Absent on older jobs means false."}},"allOf":[{"if":{"properties":{"status":{"const":"completed"}}},"then":{"properties":{"track":{},"expiresAt":{}},"required":["track","expiresAt"]}},{"if":{"properties":{"status":{"const":"running"}}},"then":{"not":{"properties":{"track":{}},"required":["track"]}}},{"if":{"properties":{"status":{"const":"queued"}}},"then":{"not":{"properties":{"track":{}},"required":["track"]}}},{"if":{"properties":{"status":{"const":"failed"}}},"then":{"properties":{"errorCode":{},"message":{}},"required":["errorCode","message"],"not":{"properties":{"track":{}},"required":["track"]}}},{"if":{"properties":{"status":{"const":"cancelled"}}},"then":{"properties":{"errorCode":{},"message":{}},"required":["errorCode","message"],"not":{"properties":{"track":{}},"required":["track"]}}},{"if":{"properties":{"partialTrack":{}},"required":["partialTrack"]},"then":{"properties":{"status":{"const":"running"}}}}]};
const schema42 = {"type":"string","enum":["validation_failed","unauthorized","unsupported_video","audio_unavailable","audio_acquisition_failed","queue_publication_failed","generation_cancelled","generation_not_cancellable","transcription_failed","enrichment_failed","rate_limited","not_found","expired","lyrics_correction_in_progress","lyrics_incomplete","lyrics_do_not_match","lyrics_correction_failed","internal_error","provider_not_configured","provider_unavailable"]};
const formats6 = /^(?:urn:uuid:)?[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/i;
const formats12 = { validate: /^\d\d\d\d-[0-1]\d-[0-3]\dt(?:[0-2]\d:[0-5]\d:[0-5]\d|23:59:60)(?:\.\d+)?(?:z|[+-]\d\d(?::?\d\d)?)$/i };
const schema41 = {"$schema":"https://json-schema.org/draft/2020-12/schema","$id":"https://transcribed-subtitle-extension.local/schemas/track-response.schema.json","title":"TrackResponse","type":"object","additionalProperties":false,"required":["trackId","jobId","youtubeVideoId","sourceLanguage","targetLanguage","generatedAt","expiresAt","webVtt","cues"],"properties":{"trackId":{"type":"string","format":"uuid"},"jobId":{"type":"string","format":"uuid"},"youtubeVideoId":{"type":"string","pattern":"^[A-Za-z0-9_-]{11}$"},"sourceLanguage":{"type":"string","enum":["auto","bel","bos","bul","cat","hrv","ces","dan","nld","eng","est","fin","fra","glg","deu","ell","hun","isl","ind","ita","jpn","kan","lav","mkd","msa","mal","nor","pol","por","ron","rus","slk","spa","swe","tur","ukr","vie","hye","aze","ben","yue","fil","kat","guj","hin","kaz","lit","mlt","cmn","mar","nep","ori","fas","srp","slv","swa","tam","tel","afr","ara","asm","ast","mya","hau","heb","jav","kor","kir","ltz","mri","oci","pan","tgk","tha","uzb","cym","amh","lug","ibo","gle","khm","kur","lao","mon","nso","pus","sna","snd","som","urd","wol","xho","yor","zul"]},"targetLanguage":{"type":"string","enum":["bel","bos","bul","cat","hrv","ces","dan","nld","eng","est","fin","fra","glg","deu","ell","hun","isl","ind","ita","jpn","kan","lav","mkd","msa","mal","nor","pol","por","ron","rus","slk","spa","swe","tur","ukr","vie","hye","aze","ben","yue","fil","kat","guj","hin","kaz","lit","mlt","cmn","mar","nep","ori","fas","srp","slv","swa","tam","tel","afr","ara","asm","ast","mya","hau","heb","jav","kor","kir","ltz","mri","oci","pan","tgk","tha","uzb","cym","amh","lug","ibo","gle","khm","kur","lao","mon","nso","pus","sna","snd","som","urd","wol","xho","yor","zul"]},"generatedAt":{"type":"string","format":"date-time"},"expiresAt":{"type":["string","null"],"format":"date-time"},"webVtt":{"type":"string","minLength":1},"cues":{"type":"array","minItems":1,"items":{"$ref":"cue.schema.json"}},"detectedSourceLanguage":{"type":"string","enum":["bel","bos","bul","cat","hrv","ces","dan","nld","eng","est","fin","fra","glg","deu","ell","hun","isl","ind","ita","jpn","kan","lav","mkd","msa","mal","nor","pol","por","ron","rus","slk","spa","swe","tur","ukr","vie","hye","aze","ben","yue","fil","kat","guj","hin","kaz","lit","mlt","cmn","mar","nep","ori","fas","srp","slv","swa","tam","tel","afr","ara","asm","ast","mya","hau","heb","jav","kor","kir","ltz","mri","oci","pan","tgk","tha","uzb","cym","amh","lug","ibo","gle","khm","kur","lao","mon","nso","pus","sna","snd","som","urd","wol","xho","yor","zul"],"description":"Provider-detected source language when sourceLanguage was auto and detection produced a catalog language."}}};

function validate28(data, {instancePath="", parentData, parentDataProperty, rootData=data, dynamicAnchors={}}={}){
/*# sourceURL="https://transcribed-subtitle-extension.local/schemas/track-response.schema.json" */;
let vErrors = null;
let errors = 0;
const evaluated0 = validate28.evaluated;
if(evaluated0.dynamicProps){
evaluated0.props = undefined;
}
if(evaluated0.dynamicItems){
evaluated0.items = undefined;
}
if(errors === 0){
if(data && typeof data == "object" && !Array.isArray(data)){
let missing0;
if((((((((((data.trackId === undefined) && (missing0 = "trackId")) || ((data.jobId === undefined) && (missing0 = "jobId"))) || ((data.youtubeVideoId === undefined) && (missing0 = "youtubeVideoId"))) || ((data.sourceLanguage === undefined) && (missing0 = "sourceLanguage"))) || ((data.targetLanguage === undefined) && (missing0 = "targetLanguage"))) || ((data.generatedAt === undefined) && (missing0 = "generatedAt"))) || ((data.expiresAt === undefined) && (missing0 = "expiresAt"))) || ((data.webVtt === undefined) && (missing0 = "webVtt"))) || ((data.cues === undefined) && (missing0 = "cues"))){
validate28.errors = [{instancePath,schemaPath:"#/required",keyword:"required",params:{missingProperty: missing0},message:"must have required property '"+missing0+"'"}];
return false;
}
else {
const _errs1 = errors;
for(const key0 in data){
if(!(func14.call(schema41.properties, key0))){
validate28.errors = [{instancePath,schemaPath:"#/additionalProperties",keyword:"additionalProperties",params:{additionalProperty: key0},message:"must NOT have additional properties"}];
return false;
break;
}
}
if(_errs1 === errors){
if(data.trackId !== undefined){
let data0 = data.trackId;
const _errs2 = errors;
if(errors === _errs2){
if(errors === _errs2){
if(typeof data0 === "string"){
if(!(formats6.test(data0))){
validate28.errors = [{instancePath:instancePath+"/trackId",schemaPath:"#/properties/trackId/format",keyword:"format",params:{format: "uuid"},message:"must match format \""+"uuid"+"\""}];
return false;
}
}
else {
validate28.errors = [{instancePath:instancePath+"/trackId",schemaPath:"#/properties/trackId/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
}
var valid0 = _errs2 === errors;
}
else {
var valid0 = true;
}
if(valid0){
if(data.jobId !== undefined){
let data1 = data.jobId;
const _errs4 = errors;
if(errors === _errs4){
if(errors === _errs4){
if(typeof data1 === "string"){
if(!(formats6.test(data1))){
validate28.errors = [{instancePath:instancePath+"/jobId",schemaPath:"#/properties/jobId/format",keyword:"format",params:{format: "uuid"},message:"must match format \""+"uuid"+"\""}];
return false;
}
}
else {
validate28.errors = [{instancePath:instancePath+"/jobId",schemaPath:"#/properties/jobId/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
}
var valid0 = _errs4 === errors;
}
else {
var valid0 = true;
}
if(valid0){
if(data.youtubeVideoId !== undefined){
let data2 = data.youtubeVideoId;
const _errs6 = errors;
if(errors === _errs6){
if(typeof data2 === "string"){
if(!pattern7.test(data2)){
validate28.errors = [{instancePath:instancePath+"/youtubeVideoId",schemaPath:"#/properties/youtubeVideoId/pattern",keyword:"pattern",params:{pattern: "^[A-Za-z0-9_-]{11}$"},message:"must match pattern \""+"^[A-Za-z0-9_-]{11}$"+"\""}];
return false;
}
}
else {
validate28.errors = [{instancePath:instancePath+"/youtubeVideoId",schemaPath:"#/properties/youtubeVideoId/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid0 = _errs6 === errors;
}
else {
var valid0 = true;
}
if(valid0){
if(data.sourceLanguage !== undefined){
let data3 = data.sourceLanguage;
const _errs8 = errors;
if(typeof data3 !== "string"){
validate28.errors = [{instancePath:instancePath+"/sourceLanguage",schemaPath:"#/properties/sourceLanguage/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
if(!((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((data3 === "auto") || (data3 === "bel")) || (data3 === "bos")) || (data3 === "bul")) || (data3 === "cat")) || (data3 === "hrv")) || (data3 === "ces")) || (data3 === "dan")) || (data3 === "nld")) || (data3 === "eng")) || (data3 === "est")) || (data3 === "fin")) || (data3 === "fra")) || (data3 === "glg")) || (data3 === "deu")) || (data3 === "ell")) || (data3 === "hun")) || (data3 === "isl")) || (data3 === "ind")) || (data3 === "ita")) || (data3 === "jpn")) || (data3 === "kan")) || (data3 === "lav")) || (data3 === "mkd")) || (data3 === "msa")) || (data3 === "mal")) || (data3 === "nor")) || (data3 === "pol")) || (data3 === "por")) || (data3 === "ron")) || (data3 === "rus")) || (data3 === "slk")) || (data3 === "spa")) || (data3 === "swe")) || (data3 === "tur")) || (data3 === "ukr")) || (data3 === "vie")) || (data3 === "hye")) || (data3 === "aze")) || (data3 === "ben")) || (data3 === "yue")) || (data3 === "fil")) || (data3 === "kat")) || (data3 === "guj")) || (data3 === "hin")) || (data3 === "kaz")) || (data3 === "lit")) || (data3 === "mlt")) || (data3 === "cmn")) || (data3 === "mar")) || (data3 === "nep")) || (data3 === "ori")) || (data3 === "fas")) || (data3 === "srp")) || (data3 === "slv")) || (data3 === "swa")) || (data3 === "tam")) || (data3 === "tel")) || (data3 === "afr")) || (data3 === "ara")) || (data3 === "asm")) || (data3 === "ast")) || (data3 === "mya")) || (data3 === "hau")) || (data3 === "heb")) || (data3 === "jav")) || (data3 === "kor")) || (data3 === "kir")) || (data3 === "ltz")) || (data3 === "mri")) || (data3 === "oci")) || (data3 === "pan")) || (data3 === "tgk")) || (data3 === "tha")) || (data3 === "uzb")) || (data3 === "cym")) || (data3 === "amh")) || (data3 === "lug")) || (data3 === "ibo")) || (data3 === "gle")) || (data3 === "khm")) || (data3 === "kur")) || (data3 === "lao")) || (data3 === "mon")) || (data3 === "nso")) || (data3 === "pus")) || (data3 === "sna")) || (data3 === "snd")) || (data3 === "som")) || (data3 === "urd")) || (data3 === "wol")) || (data3 === "xho")) || (data3 === "yor")) || (data3 === "zul"))){
validate28.errors = [{instancePath:instancePath+"/sourceLanguage",schemaPath:"#/properties/sourceLanguage/enum",keyword:"enum",params:{allowedValues: schema41.properties.sourceLanguage.enum},message:"must be equal to one of the allowed values"}];
return false;
}
var valid0 = _errs8 === errors;
}
else {
var valid0 = true;
}
if(valid0){
if(data.targetLanguage !== undefined){
let data4 = data.targetLanguage;
const _errs10 = errors;
if(typeof data4 !== "string"){
validate28.errors = [{instancePath:instancePath+"/targetLanguage",schemaPath:"#/properties/targetLanguage/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
if(!(((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((data4 === "bel") || (data4 === "bos")) || (data4 === "bul")) || (data4 === "cat")) || (data4 === "hrv")) || (data4 === "ces")) || (data4 === "dan")) || (data4 === "nld")) || (data4 === "eng")) || (data4 === "est")) || (data4 === "fin")) || (data4 === "fra")) || (data4 === "glg")) || (data4 === "deu")) || (data4 === "ell")) || (data4 === "hun")) || (data4 === "isl")) || (data4 === "ind")) || (data4 === "ita")) || (data4 === "jpn")) || (data4 === "kan")) || (data4 === "lav")) || (data4 === "mkd")) || (data4 === "msa")) || (data4 === "mal")) || (data4 === "nor")) || (data4 === "pol")) || (data4 === "por")) || (data4 === "ron")) || (data4 === "rus")) || (data4 === "slk")) || (data4 === "spa")) || (data4 === "swe")) || (data4 === "tur")) || (data4 === "ukr")) || (data4 === "vie")) || (data4 === "hye")) || (data4 === "aze")) || (data4 === "ben")) || (data4 === "yue")) || (data4 === "fil")) || (data4 === "kat")) || (data4 === "guj")) || (data4 === "hin")) || (data4 === "kaz")) || (data4 === "lit")) || (data4 === "mlt")) || (data4 === "cmn")) || (data4 === "mar")) || (data4 === "nep")) || (data4 === "ori")) || (data4 === "fas")) || (data4 === "srp")) || (data4 === "slv")) || (data4 === "swa")) || (data4 === "tam")) || (data4 === "tel")) || (data4 === "afr")) || (data4 === "ara")) || (data4 === "asm")) || (data4 === "ast")) || (data4 === "mya")) || (data4 === "hau")) || (data4 === "heb")) || (data4 === "jav")) || (data4 === "kor")) || (data4 === "kir")) || (data4 === "ltz")) || (data4 === "mri")) || (data4 === "oci")) || (data4 === "pan")) || (data4 === "tgk")) || (data4 === "tha")) || (data4 === "uzb")) || (data4 === "cym")) || (data4 === "amh")) || (data4 === "lug")) || (data4 === "ibo")) || (data4 === "gle")) || (data4 === "khm")) || (data4 === "kur")) || (data4 === "lao")) || (data4 === "mon")) || (data4 === "nso")) || (data4 === "pus")) || (data4 === "sna")) || (data4 === "snd")) || (data4 === "som")) || (data4 === "urd")) || (data4 === "wol")) || (data4 === "xho")) || (data4 === "yor")) || (data4 === "zul"))){
validate28.errors = [{instancePath:instancePath+"/targetLanguage",schemaPath:"#/properties/targetLanguage/enum",keyword:"enum",params:{allowedValues: schema41.properties.targetLanguage.enum},message:"must be equal to one of the allowed values"}];
return false;
}
var valid0 = _errs10 === errors;
}
else {
var valid0 = true;
}
if(valid0){
if(data.generatedAt !== undefined){
let data5 = data.generatedAt;
const _errs12 = errors;
if(errors === _errs12){
if(errors === _errs12){
if(typeof data5 === "string"){
if(!(formats12.validate.test(data5))){
validate28.errors = [{instancePath:instancePath+"/generatedAt",schemaPath:"#/properties/generatedAt/format",keyword:"format",params:{format: "date-time"},message:"must match format \""+"date-time"+"\""}];
return false;
}
}
else {
validate28.errors = [{instancePath:instancePath+"/generatedAt",schemaPath:"#/properties/generatedAt/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
}
var valid0 = _errs12 === errors;
}
else {
var valid0 = true;
}
if(valid0){
if(data.expiresAt !== undefined){
let data6 = data.expiresAt;
const _errs14 = errors;
if((typeof data6 !== "string") && (data6 !== null)){
validate28.errors = [{instancePath:instancePath+"/expiresAt",schemaPath:"#/properties/expiresAt/type",keyword:"type",params:{type: schema41.properties.expiresAt.type},message:"must be string,null"}];
return false;
}
if(errors === _errs14){
if(errors === _errs14){
if(typeof data6 === "string"){
if(!(formats12.validate.test(data6))){
validate28.errors = [{instancePath:instancePath+"/expiresAt",schemaPath:"#/properties/expiresAt/format",keyword:"format",params:{format: "date-time"},message:"must match format \""+"date-time"+"\""}];
return false;
}
}
}
}
var valid0 = _errs14 === errors;
}
else {
var valid0 = true;
}
if(valid0){
if(data.webVtt !== undefined){
let data7 = data.webVtt;
const _errs16 = errors;
if(errors === _errs16){
if(typeof data7 === "string"){
if(func1(data7) < 1){
validate28.errors = [{instancePath:instancePath+"/webVtt",schemaPath:"#/properties/webVtt/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
}
else {
validate28.errors = [{instancePath:instancePath+"/webVtt",schemaPath:"#/properties/webVtt/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid0 = _errs16 === errors;
}
else {
var valid0 = true;
}
if(valid0){
if(data.cues !== undefined){
let data8 = data.cues;
const _errs18 = errors;
if(errors === _errs18){
if(Array.isArray(data8)){
if(data8.length < 1){
validate28.errors = [{instancePath:instancePath+"/cues",schemaPath:"#/properties/cues/minItems",keyword:"minItems",params:{limit: 1},message:"must NOT have fewer than 1 items"}];
return false;
}
else {
var valid1 = true;
const len0 = data8.length;
for(let i0=0; i0<len0; i0++){
const _errs20 = errors;
if(!(validate24(data8[i0], {instancePath:instancePath+"/cues/" + i0,parentData:data8,parentDataProperty:i0,rootData,dynamicAnchors}))){
vErrors = vErrors === null ? validate24.errors : vErrors.concat(validate24.errors);
errors = vErrors.length;
}
var valid1 = _errs20 === errors;
if(!valid1){
break;
}
}
}
}
else {
validate28.errors = [{instancePath:instancePath+"/cues",schemaPath:"#/properties/cues/type",keyword:"type",params:{type: "array"},message:"must be array"}];
return false;
}
}
var valid0 = _errs18 === errors;
}
else {
var valid0 = true;
}
if(valid0){
if(data.detectedSourceLanguage !== undefined){
let data10 = data.detectedSourceLanguage;
const _errs21 = errors;
if(typeof data10 !== "string"){
validate28.errors = [{instancePath:instancePath+"/detectedSourceLanguage",schemaPath:"#/properties/detectedSourceLanguage/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
if(!(((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((data10 === "bel") || (data10 === "bos")) || (data10 === "bul")) || (data10 === "cat")) || (data10 === "hrv")) || (data10 === "ces")) || (data10 === "dan")) || (data10 === "nld")) || (data10 === "eng")) || (data10 === "est")) || (data10 === "fin")) || (data10 === "fra")) || (data10 === "glg")) || (data10 === "deu")) || (data10 === "ell")) || (data10 === "hun")) || (data10 === "isl")) || (data10 === "ind")) || (data10 === "ita")) || (data10 === "jpn")) || (data10 === "kan")) || (data10 === "lav")) || (data10 === "mkd")) || (data10 === "msa")) || (data10 === "mal")) || (data10 === "nor")) || (data10 === "pol")) || (data10 === "por")) || (data10 === "ron")) || (data10 === "rus")) || (data10 === "slk")) || (data10 === "spa")) || (data10 === "swe")) || (data10 === "tur")) || (data10 === "ukr")) || (data10 === "vie")) || (data10 === "hye")) || (data10 === "aze")) || (data10 === "ben")) || (data10 === "yue")) || (data10 === "fil")) || (data10 === "kat")) || (data10 === "guj")) || (data10 === "hin")) || (data10 === "kaz")) || (data10 === "lit")) || (data10 === "mlt")) || (data10 === "cmn")) || (data10 === "mar")) || (data10 === "nep")) || (data10 === "ori")) || (data10 === "fas")) || (data10 === "srp")) || (data10 === "slv")) || (data10 === "swa")) || (data10 === "tam")) || (data10 === "tel")) || (data10 === "afr")) || (data10 === "ara")) || (data10 === "asm")) || (data10 === "ast")) || (data10 === "mya")) || (data10 === "hau")) || (data10 === "heb")) || (data10 === "jav")) || (data10 === "kor")) || (data10 === "kir")) || (data10 === "ltz")) || (data10 === "mri")) || (data10 === "oci")) || (data10 === "pan")) || (data10 === "tgk")) || (data10 === "tha")) || (data10 === "uzb")) || (data10 === "cym")) || (data10 === "amh")) || (data10 === "lug")) || (data10 === "ibo")) || (data10 === "gle")) || (data10 === "khm")) || (data10 === "kur")) || (data10 === "lao")) || (data10 === "mon")) || (data10 === "nso")) || (data10 === "pus")) || (data10 === "sna")) || (data10 === "snd")) || (data10 === "som")) || (data10 === "urd")) || (data10 === "wol")) || (data10 === "xho")) || (data10 === "yor")) || (data10 === "zul"))){
validate28.errors = [{instancePath:instancePath+"/detectedSourceLanguage",schemaPath:"#/properties/detectedSourceLanguage/enum",keyword:"enum",params:{allowedValues: schema41.properties.detectedSourceLanguage.enum},message:"must be equal to one of the allowed values"}];
return false;
}
var valid0 = _errs21 === errors;
}
else {
var valid0 = true;
}
}
}
}
}
}
}
}
}
}
}
}
}
else {
validate28.errors = [{instancePath,schemaPath:"#/type",keyword:"type",params:{type: "object"},message:"must be object"}];
return false;
}
}
validate28.errors = vErrors;
return errors === 0;
}
validate28.evaluated = {"props":true,"dynamicProps":false,"dynamicItems":false};

const schema43 = {"$schema":"https://json-schema.org/draft/2020-12/schema","$id":"https://transcribed-subtitle-extension.local/schemas/partial-track-response.schema.json","title":"PartialTrackResponse","description":"Cues available for a still-running subtitle job. Stable source cues append as contiguous transcription chunks arrive; translations and romanization fill in per batch as the pipeline progresses. Tokens are never included: word cards need the finalized track.","type":"object","additionalProperties":false,"required":["jobId","youtubeVideoId","revision","cues"],"properties":{"jobId":{"type":"string","format":"uuid"},"youtubeVideoId":{"type":"string","pattern":"^[A-Za-z0-9_-]{11}$"},"revision":{"type":"integer","minimum":1,"description":"Revision of the source prefix plus completed annotation batches. Monotonically increasing for a given run; re-render when it changes."},"cues":{"type":"array","minItems":1,"items":{"$ref":"#/$defs/PartialSubtitleCue"}},"readyThroughMs":{"type":"integer","minimum":0,"description":"End of the contiguous prefix with completed analysis, in video milliseconds. Later completed batches do not advance this value across an unfinished batch."}},"$defs":{"PartialSubtitleCue":{"title":"PartialSubtitleCue","type":"object","additionalProperties":false,"required":["cueId","index","startMs","endMs","sourceText"],"properties":{"cueId":{"type":"string","minLength":1},"index":{"type":"integer","minimum":0},"startMs":{"type":"integer","minimum":0},"endMs":{"type":"integer","minimum":1},"sourceText":{"type":"string","minLength":1},"translatedText":{"type":"string","minLength":1},"romanization":{"type":"string","minLength":1}}}}};
const schema44 = {"title":"PartialSubtitleCue","type":"object","additionalProperties":false,"required":["cueId","index","startMs","endMs","sourceText"],"properties":{"cueId":{"type":"string","minLength":1},"index":{"type":"integer","minimum":0},"startMs":{"type":"integer","minimum":0},"endMs":{"type":"integer","minimum":1},"sourceText":{"type":"string","minLength":1},"translatedText":{"type":"string","minLength":1},"romanization":{"type":"string","minLength":1}}};

function validate31(data, {instancePath="", parentData, parentDataProperty, rootData=data, dynamicAnchors={}}={}){
/*# sourceURL="https://transcribed-subtitle-extension.local/schemas/partial-track-response.schema.json" */;
let vErrors = null;
let errors = 0;
const evaluated0 = validate31.evaluated;
if(evaluated0.dynamicProps){
evaluated0.props = undefined;
}
if(evaluated0.dynamicItems){
evaluated0.items = undefined;
}
if(errors === 0){
if(data && typeof data == "object" && !Array.isArray(data)){
let missing0;
if(((((data.jobId === undefined) && (missing0 = "jobId")) || ((data.youtubeVideoId === undefined) && (missing0 = "youtubeVideoId"))) || ((data.revision === undefined) && (missing0 = "revision"))) || ((data.cues === undefined) && (missing0 = "cues"))){
validate31.errors = [{instancePath,schemaPath:"#/required",keyword:"required",params:{missingProperty: missing0},message:"must have required property '"+missing0+"'"}];
return false;
}
else {
const _errs1 = errors;
for(const key0 in data){
if(!(((((key0 === "jobId") || (key0 === "youtubeVideoId")) || (key0 === "revision")) || (key0 === "cues")) || (key0 === "readyThroughMs"))){
validate31.errors = [{instancePath,schemaPath:"#/additionalProperties",keyword:"additionalProperties",params:{additionalProperty: key0},message:"must NOT have additional properties"}];
return false;
break;
}
}
if(_errs1 === errors){
if(data.jobId !== undefined){
let data0 = data.jobId;
const _errs2 = errors;
if(errors === _errs2){
if(errors === _errs2){
if(typeof data0 === "string"){
if(!(formats6.test(data0))){
validate31.errors = [{instancePath:instancePath+"/jobId",schemaPath:"#/properties/jobId/format",keyword:"format",params:{format: "uuid"},message:"must match format \""+"uuid"+"\""}];
return false;
}
}
else {
validate31.errors = [{instancePath:instancePath+"/jobId",schemaPath:"#/properties/jobId/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
}
var valid0 = _errs2 === errors;
}
else {
var valid0 = true;
}
if(valid0){
if(data.youtubeVideoId !== undefined){
let data1 = data.youtubeVideoId;
const _errs4 = errors;
if(errors === _errs4){
if(typeof data1 === "string"){
if(!pattern7.test(data1)){
validate31.errors = [{instancePath:instancePath+"/youtubeVideoId",schemaPath:"#/properties/youtubeVideoId/pattern",keyword:"pattern",params:{pattern: "^[A-Za-z0-9_-]{11}$"},message:"must match pattern \""+"^[A-Za-z0-9_-]{11}$"+"\""}];
return false;
}
}
else {
validate31.errors = [{instancePath:instancePath+"/youtubeVideoId",schemaPath:"#/properties/youtubeVideoId/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid0 = _errs4 === errors;
}
else {
var valid0 = true;
}
if(valid0){
if(data.revision !== undefined){
let data2 = data.revision;
const _errs6 = errors;
if(!(((typeof data2 == "number") && (!(data2 % 1) && !isNaN(data2))) && (isFinite(data2)))){
validate31.errors = [{instancePath:instancePath+"/revision",schemaPath:"#/properties/revision/type",keyword:"type",params:{type: "integer"},message:"must be integer"}];
return false;
}
if(errors === _errs6){
if((typeof data2 == "number") && (isFinite(data2))){
if(data2 < 1 || isNaN(data2)){
validate31.errors = [{instancePath:instancePath+"/revision",schemaPath:"#/properties/revision/minimum",keyword:"minimum",params:{comparison: ">=", limit: 1},message:"must be >= 1"}];
return false;
}
}
}
var valid0 = _errs6 === errors;
}
else {
var valid0 = true;
}
if(valid0){
if(data.cues !== undefined){
let data3 = data.cues;
const _errs8 = errors;
if(errors === _errs8){
if(Array.isArray(data3)){
if(data3.length < 1){
validate31.errors = [{instancePath:instancePath+"/cues",schemaPath:"#/properties/cues/minItems",keyword:"minItems",params:{limit: 1},message:"must NOT have fewer than 1 items"}];
return false;
}
else {
var valid1 = true;
const len0 = data3.length;
for(let i0=0; i0<len0; i0++){
let data4 = data3[i0];
const _errs10 = errors;
const _errs11 = errors;
if(errors === _errs11){
if(data4 && typeof data4 == "object" && !Array.isArray(data4)){
let missing1;
if((((((data4.cueId === undefined) && (missing1 = "cueId")) || ((data4.index === undefined) && (missing1 = "index"))) || ((data4.startMs === undefined) && (missing1 = "startMs"))) || ((data4.endMs === undefined) && (missing1 = "endMs"))) || ((data4.sourceText === undefined) && (missing1 = "sourceText"))){
validate31.errors = [{instancePath:instancePath+"/cues/" + i0,schemaPath:"#/$defs/PartialSubtitleCue/required",keyword:"required",params:{missingProperty: missing1},message:"must have required property '"+missing1+"'"}];
return false;
}
else {
const _errs13 = errors;
for(const key1 in data4){
if(!(((((((key1 === "cueId") || (key1 === "index")) || (key1 === "startMs")) || (key1 === "endMs")) || (key1 === "sourceText")) || (key1 === "translatedText")) || (key1 === "romanization"))){
validate31.errors = [{instancePath:instancePath+"/cues/" + i0,schemaPath:"#/$defs/PartialSubtitleCue/additionalProperties",keyword:"additionalProperties",params:{additionalProperty: key1},message:"must NOT have additional properties"}];
return false;
break;
}
}
if(_errs13 === errors){
if(data4.cueId !== undefined){
let data5 = data4.cueId;
const _errs14 = errors;
if(errors === _errs14){
if(typeof data5 === "string"){
if(func1(data5) < 1){
validate31.errors = [{instancePath:instancePath+"/cues/" + i0+"/cueId",schemaPath:"#/$defs/PartialSubtitleCue/properties/cueId/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
}
else {
validate31.errors = [{instancePath:instancePath+"/cues/" + i0+"/cueId",schemaPath:"#/$defs/PartialSubtitleCue/properties/cueId/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid3 = _errs14 === errors;
}
else {
var valid3 = true;
}
if(valid3){
if(data4.index !== undefined){
let data6 = data4.index;
const _errs16 = errors;
if(!(((typeof data6 == "number") && (!(data6 % 1) && !isNaN(data6))) && (isFinite(data6)))){
validate31.errors = [{instancePath:instancePath+"/cues/" + i0+"/index",schemaPath:"#/$defs/PartialSubtitleCue/properties/index/type",keyword:"type",params:{type: "integer"},message:"must be integer"}];
return false;
}
if(errors === _errs16){
if((typeof data6 == "number") && (isFinite(data6))){
if(data6 < 0 || isNaN(data6)){
validate31.errors = [{instancePath:instancePath+"/cues/" + i0+"/index",schemaPath:"#/$defs/PartialSubtitleCue/properties/index/minimum",keyword:"minimum",params:{comparison: ">=", limit: 0},message:"must be >= 0"}];
return false;
}
}
}
var valid3 = _errs16 === errors;
}
else {
var valid3 = true;
}
if(valid3){
if(data4.startMs !== undefined){
let data7 = data4.startMs;
const _errs18 = errors;
if(!(((typeof data7 == "number") && (!(data7 % 1) && !isNaN(data7))) && (isFinite(data7)))){
validate31.errors = [{instancePath:instancePath+"/cues/" + i0+"/startMs",schemaPath:"#/$defs/PartialSubtitleCue/properties/startMs/type",keyword:"type",params:{type: "integer"},message:"must be integer"}];
return false;
}
if(errors === _errs18){
if((typeof data7 == "number") && (isFinite(data7))){
if(data7 < 0 || isNaN(data7)){
validate31.errors = [{instancePath:instancePath+"/cues/" + i0+"/startMs",schemaPath:"#/$defs/PartialSubtitleCue/properties/startMs/minimum",keyword:"minimum",params:{comparison: ">=", limit: 0},message:"must be >= 0"}];
return false;
}
}
}
var valid3 = _errs18 === errors;
}
else {
var valid3 = true;
}
if(valid3){
if(data4.endMs !== undefined){
let data8 = data4.endMs;
const _errs20 = errors;
if(!(((typeof data8 == "number") && (!(data8 % 1) && !isNaN(data8))) && (isFinite(data8)))){
validate31.errors = [{instancePath:instancePath+"/cues/" + i0+"/endMs",schemaPath:"#/$defs/PartialSubtitleCue/properties/endMs/type",keyword:"type",params:{type: "integer"},message:"must be integer"}];
return false;
}
if(errors === _errs20){
if((typeof data8 == "number") && (isFinite(data8))){
if(data8 < 1 || isNaN(data8)){
validate31.errors = [{instancePath:instancePath+"/cues/" + i0+"/endMs",schemaPath:"#/$defs/PartialSubtitleCue/properties/endMs/minimum",keyword:"minimum",params:{comparison: ">=", limit: 1},message:"must be >= 1"}];
return false;
}
}
}
var valid3 = _errs20 === errors;
}
else {
var valid3 = true;
}
if(valid3){
if(data4.sourceText !== undefined){
let data9 = data4.sourceText;
const _errs22 = errors;
if(errors === _errs22){
if(typeof data9 === "string"){
if(func1(data9) < 1){
validate31.errors = [{instancePath:instancePath+"/cues/" + i0+"/sourceText",schemaPath:"#/$defs/PartialSubtitleCue/properties/sourceText/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
}
else {
validate31.errors = [{instancePath:instancePath+"/cues/" + i0+"/sourceText",schemaPath:"#/$defs/PartialSubtitleCue/properties/sourceText/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid3 = _errs22 === errors;
}
else {
var valid3 = true;
}
if(valid3){
if(data4.translatedText !== undefined){
let data10 = data4.translatedText;
const _errs24 = errors;
if(errors === _errs24){
if(typeof data10 === "string"){
if(func1(data10) < 1){
validate31.errors = [{instancePath:instancePath+"/cues/" + i0+"/translatedText",schemaPath:"#/$defs/PartialSubtitleCue/properties/translatedText/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
}
else {
validate31.errors = [{instancePath:instancePath+"/cues/" + i0+"/translatedText",schemaPath:"#/$defs/PartialSubtitleCue/properties/translatedText/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid3 = _errs24 === errors;
}
else {
var valid3 = true;
}
if(valid3){
if(data4.romanization !== undefined){
let data11 = data4.romanization;
const _errs26 = errors;
if(errors === _errs26){
if(typeof data11 === "string"){
if(func1(data11) < 1){
validate31.errors = [{instancePath:instancePath+"/cues/" + i0+"/romanization",schemaPath:"#/$defs/PartialSubtitleCue/properties/romanization/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
}
else {
validate31.errors = [{instancePath:instancePath+"/cues/" + i0+"/romanization",schemaPath:"#/$defs/PartialSubtitleCue/properties/romanization/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid3 = _errs26 === errors;
}
else {
var valid3 = true;
}
}
}
}
}
}
}
}
}
}
else {
validate31.errors = [{instancePath:instancePath+"/cues/" + i0,schemaPath:"#/$defs/PartialSubtitleCue/type",keyword:"type",params:{type: "object"},message:"must be object"}];
return false;
}
}
var valid1 = _errs10 === errors;
if(!valid1){
break;
}
}
}
}
else {
validate31.errors = [{instancePath:instancePath+"/cues",schemaPath:"#/properties/cues/type",keyword:"type",params:{type: "array"},message:"must be array"}];
return false;
}
}
var valid0 = _errs8 === errors;
}
else {
var valid0 = true;
}
if(valid0){
if(data.readyThroughMs !== undefined){
let data12 = data.readyThroughMs;
const _errs28 = errors;
if(!(((typeof data12 == "number") && (!(data12 % 1) && !isNaN(data12))) && (isFinite(data12)))){
validate31.errors = [{instancePath:instancePath+"/readyThroughMs",schemaPath:"#/properties/readyThroughMs/type",keyword:"type",params:{type: "integer"},message:"must be integer"}];
return false;
}
if(errors === _errs28){
if((typeof data12 == "number") && (isFinite(data12))){
if(data12 < 0 || isNaN(data12)){
validate31.errors = [{instancePath:instancePath+"/readyThroughMs",schemaPath:"#/properties/readyThroughMs/minimum",keyword:"minimum",params:{comparison: ">=", limit: 0},message:"must be >= 0"}];
return false;
}
}
}
var valid0 = _errs28 === errors;
}
else {
var valid0 = true;
}
}
}
}
}
}
}
}
else {
validate31.errors = [{instancePath,schemaPath:"#/type",keyword:"type",params:{type: "object"},message:"must be object"}];
return false;
}
}
validate31.errors = vErrors;
return errors === 0;
}
validate31.evaluated = {"props":true,"dynamicProps":false,"dynamicItems":false};


function validate27(data, {instancePath="", parentData, parentDataProperty, rootData=data, dynamicAnchors={}}={}){
/*# sourceURL="https://transcribed-subtitle-extension.local/schemas/job-response.schema.json" */;
let vErrors = null;
let errors = 0;
const evaluated0 = validate27.evaluated;
if(evaluated0.dynamicProps){
evaluated0.props = undefined;
}
if(evaluated0.dynamicItems){
evaluated0.items = undefined;
}
const _errs1 = errors;
const _errs2 = errors;
let valid1 = true;
const _errs3 = errors;
if(data && typeof data == "object" && !Array.isArray(data)){
if(data.status !== undefined){
if("completed" !== data.status){
const err0 = {};
if(vErrors === null){
vErrors = [err0];
}
else {
vErrors.push(err0);
}
errors++;
}
}
}
var _valid0 = _errs3 === errors;
errors = _errs2;
if(vErrors !== null){
if(_errs2){
vErrors.length = _errs2;
}
else {
vErrors = null;
}
}
if(_valid0){
const _errs5 = errors;
if(data && typeof data == "object" && !Array.isArray(data)){
let missing0;
if(((data.track === undefined) && (missing0 = "track")) || ((data.expiresAt === undefined) && (missing0 = "expiresAt"))){
validate27.errors = [{instancePath,schemaPath:"#/allOf/0/then/required",keyword:"required",params:{missingProperty: missing0},message:"must have required property '"+missing0+"'"}];
return false;
}
}
var _valid0 = _errs5 === errors;
valid1 = _valid0;
if(valid1){
var props0 = {};
props0.track = true;
props0.expiresAt = true;
props0.status = true;
}
}
if(!valid1){
const err1 = {instancePath,schemaPath:"#/allOf/0/if",keyword:"if",params:{failingKeyword: "then"},message:"must match \"then\" schema"};
if(vErrors === null){
vErrors = [err1];
}
else {
vErrors.push(err1);
}
errors++;
validate27.errors = vErrors;
return false;
}
var valid0 = _errs1 === errors;
if(valid0){
const _errs6 = errors;
const _errs7 = errors;
let valid3 = true;
const _errs8 = errors;
if(data && typeof data == "object" && !Array.isArray(data)){
if(data.status !== undefined){
if("running" !== data.status){
const err2 = {};
if(vErrors === null){
vErrors = [err2];
}
else {
vErrors.push(err2);
}
errors++;
}
}
}
var _valid1 = _errs8 === errors;
errors = _errs7;
if(vErrors !== null){
if(_errs7){
vErrors.length = _errs7;
}
else {
vErrors = null;
}
}
if(_valid1){
const _errs10 = errors;
const _errs11 = errors;
const _errs12 = errors;
if(data && typeof data == "object" && !Array.isArray(data)){
let missing1;
if((data.track === undefined) && (missing1 = "track")){
const err3 = {};
if(vErrors === null){
vErrors = [err3];
}
else {
vErrors.push(err3);
}
errors++;
}
}
var valid5 = _errs12 === errors;
if(valid5){
validate27.errors = [{instancePath,schemaPath:"#/allOf/1/then/not",keyword:"not",params:{},message:"must NOT be valid"}];
return false;
}
else {
errors = _errs11;
if(vErrors !== null){
if(_errs11){
vErrors.length = _errs11;
}
else {
vErrors = null;
}
}
}
var _valid1 = _errs10 === errors;
valid3 = _valid1;
}
if(!valid3){
const err4 = {instancePath,schemaPath:"#/allOf/1/if",keyword:"if",params:{failingKeyword: "then"},message:"must match \"then\" schema"};
if(vErrors === null){
vErrors = [err4];
}
else {
vErrors.push(err4);
}
errors++;
validate27.errors = vErrors;
return false;
}
var valid0 = _errs6 === errors;
if(valid0){
if(props0 !== true){
props0 = props0 || {};
props0.status = true;
}
const _errs13 = errors;
const _errs14 = errors;
let valid6 = true;
const _errs15 = errors;
if(data && typeof data == "object" && !Array.isArray(data)){
if(data.status !== undefined){
if("queued" !== data.status){
const err5 = {};
if(vErrors === null){
vErrors = [err5];
}
else {
vErrors.push(err5);
}
errors++;
}
}
}
var _valid2 = _errs15 === errors;
errors = _errs14;
if(vErrors !== null){
if(_errs14){
vErrors.length = _errs14;
}
else {
vErrors = null;
}
}
if(_valid2){
const _errs17 = errors;
const _errs18 = errors;
const _errs19 = errors;
if(data && typeof data == "object" && !Array.isArray(data)){
let missing2;
if((data.track === undefined) && (missing2 = "track")){
const err6 = {};
if(vErrors === null){
vErrors = [err6];
}
else {
vErrors.push(err6);
}
errors++;
}
}
var valid8 = _errs19 === errors;
if(valid8){
validate27.errors = [{instancePath,schemaPath:"#/allOf/2/then/not",keyword:"not",params:{},message:"must NOT be valid"}];
return false;
}
else {
errors = _errs18;
if(vErrors !== null){
if(_errs18){
vErrors.length = _errs18;
}
else {
vErrors = null;
}
}
}
var _valid2 = _errs17 === errors;
valid6 = _valid2;
}
if(!valid6){
const err7 = {instancePath,schemaPath:"#/allOf/2/if",keyword:"if",params:{failingKeyword: "then"},message:"must match \"then\" schema"};
if(vErrors === null){
vErrors = [err7];
}
else {
vErrors.push(err7);
}
errors++;
validate27.errors = vErrors;
return false;
}
var valid0 = _errs13 === errors;
if(valid0){
if(props0 !== true){
props0 = props0 || {};
props0.status = true;
}
const _errs20 = errors;
const _errs21 = errors;
let valid9 = true;
const _errs22 = errors;
if(data && typeof data == "object" && !Array.isArray(data)){
if(data.status !== undefined){
if("failed" !== data.status){
const err8 = {};
if(vErrors === null){
vErrors = [err8];
}
else {
vErrors.push(err8);
}
errors++;
}
}
}
var _valid3 = _errs22 === errors;
errors = _errs21;
if(vErrors !== null){
if(_errs21){
vErrors.length = _errs21;
}
else {
vErrors = null;
}
}
if(_valid3){
const _errs24 = errors;
const _errs25 = errors;
const _errs26 = errors;
if(data && typeof data == "object" && !Array.isArray(data)){
let missing3;
if((data.track === undefined) && (missing3 = "track")){
const err9 = {};
if(vErrors === null){
vErrors = [err9];
}
else {
vErrors.push(err9);
}
errors++;
}
}
var valid11 = _errs26 === errors;
if(valid11){
validate27.errors = [{instancePath,schemaPath:"#/allOf/3/then/not",keyword:"not",params:{},message:"must NOT be valid"}];
return false;
}
else {
errors = _errs25;
if(vErrors !== null){
if(_errs25){
vErrors.length = _errs25;
}
else {
vErrors = null;
}
}
}
if(errors === _errs24){
if(data && typeof data == "object" && !Array.isArray(data)){
let missing4;
if(((data.errorCode === undefined) && (missing4 = "errorCode")) || ((data.message === undefined) && (missing4 = "message"))){
validate27.errors = [{instancePath,schemaPath:"#/allOf/3/then/required",keyword:"required",params:{missingProperty: missing4},message:"must have required property '"+missing4+"'"}];
return false;
}
}
}
var _valid3 = _errs24 === errors;
valid9 = _valid3;
if(valid9){
var props1 = {};
props1.errorCode = true;
props1.message = true;
props1.status = true;
}
}
if(!valid9){
const err10 = {instancePath,schemaPath:"#/allOf/3/if",keyword:"if",params:{failingKeyword: "then"},message:"must match \"then\" schema"};
if(vErrors === null){
vErrors = [err10];
}
else {
vErrors.push(err10);
}
errors++;
validate27.errors = vErrors;
return false;
}
var valid0 = _errs20 === errors;
if(valid0){
if(props0 !== true && props1 !== undefined){
if(props1 === true){
props0 = true;
}
else {
props0 = props0 || {};
Object.assign(props0, props1);
}
}
const _errs27 = errors;
const _errs28 = errors;
let valid12 = true;
const _errs29 = errors;
if(data && typeof data == "object" && !Array.isArray(data)){
if(data.status !== undefined){
if("cancelled" !== data.status){
const err11 = {};
if(vErrors === null){
vErrors = [err11];
}
else {
vErrors.push(err11);
}
errors++;
}
}
}
var _valid4 = _errs29 === errors;
errors = _errs28;
if(vErrors !== null){
if(_errs28){
vErrors.length = _errs28;
}
else {
vErrors = null;
}
}
if(_valid4){
const _errs31 = errors;
const _errs32 = errors;
const _errs33 = errors;
if(data && typeof data == "object" && !Array.isArray(data)){
let missing5;
if((data.track === undefined) && (missing5 = "track")){
const err12 = {};
if(vErrors === null){
vErrors = [err12];
}
else {
vErrors.push(err12);
}
errors++;
}
}
var valid14 = _errs33 === errors;
if(valid14){
validate27.errors = [{instancePath,schemaPath:"#/allOf/4/then/not",keyword:"not",params:{},message:"must NOT be valid"}];
return false;
}
else {
errors = _errs32;
if(vErrors !== null){
if(_errs32){
vErrors.length = _errs32;
}
else {
vErrors = null;
}
}
}
if(errors === _errs31){
if(data && typeof data == "object" && !Array.isArray(data)){
let missing6;
if(((data.errorCode === undefined) && (missing6 = "errorCode")) || ((data.message === undefined) && (missing6 = "message"))){
validate27.errors = [{instancePath,schemaPath:"#/allOf/4/then/required",keyword:"required",params:{missingProperty: missing6},message:"must have required property '"+missing6+"'"}];
return false;
}
}
}
var _valid4 = _errs31 === errors;
valid12 = _valid4;
if(valid12){
var props2 = {};
props2.errorCode = true;
props2.message = true;
props2.status = true;
}
}
if(!valid12){
const err13 = {instancePath,schemaPath:"#/allOf/4/if",keyword:"if",params:{failingKeyword: "then"},message:"must match \"then\" schema"};
if(vErrors === null){
vErrors = [err13];
}
else {
vErrors.push(err13);
}
errors++;
validate27.errors = vErrors;
return false;
}
var valid0 = _errs27 === errors;
if(valid0){
if(props0 !== true && props2 !== undefined){
if(props2 === true){
props0 = true;
}
else {
props0 = props0 || {};
Object.assign(props0, props2);
}
}
const _errs34 = errors;
const _errs35 = errors;
let valid15 = true;
const _errs36 = errors;
if(data && typeof data == "object" && !Array.isArray(data)){
let missing7;
if((data.partialTrack === undefined) && (missing7 = "partialTrack")){
const err14 = {};
if(vErrors === null){
vErrors = [err14];
}
else {
vErrors.push(err14);
}
errors++;
}
}
var _valid5 = _errs36 === errors;
errors = _errs35;
if(vErrors !== null){
if(_errs35){
vErrors.length = _errs35;
}
else {
vErrors = null;
}
}
if(_valid5){
const _errs37 = errors;
if(data && typeof data == "object" && !Array.isArray(data)){
if(data.status !== undefined){
if("running" !== data.status){
validate27.errors = [{instancePath:instancePath+"/status",schemaPath:"#/allOf/5/then/properties/status/const",keyword:"const",params:{allowedValue: "running"},message:"must be equal to constant"}];
return false;
}
}
}
var _valid5 = _errs37 === errors;
valid15 = _valid5;
if(valid15){
var props3 = {};
props3.status = true;
props3.partialTrack = true;
}
}
if(!valid15){
const err15 = {instancePath,schemaPath:"#/allOf/5/if",keyword:"if",params:{failingKeyword: "then"},message:"must match \"then\" schema"};
if(vErrors === null){
vErrors = [err15];
}
else {
vErrors.push(err15);
}
errors++;
validate27.errors = vErrors;
return false;
}
var valid0 = _errs34 === errors;
if(valid0){
if(props0 !== true && props3 !== undefined){
if(props3 === true){
props0 = true;
}
else {
props0 = props0 || {};
Object.assign(props0, props3);
}
}
}
}
}
}
}
}
if(errors === 0){
if(data && typeof data == "object" && !Array.isArray(data)){
let missing8;
if((((((((((((((data.jobId === undefined) && (missing8 = "jobId")) || ((data.youtubeVideoId === undefined) && (missing8 = "youtubeVideoId"))) || ((data.sourceLanguage === undefined) && (missing8 = "sourceLanguage"))) || ((data.targetLanguage === undefined) && (missing8 = "targetLanguage"))) || ((data.includeRomanization === undefined) && (missing8 = "includeRomanization"))) || ((data.includeTranslation === undefined) && (missing8 = "includeTranslation"))) || ((data.status === undefined) && (missing8 = "status"))) || ((data.stage === undefined) && (missing8 = "stage"))) || ((data.progressPercent === undefined) && (missing8 = "progressPercent"))) || ((data.createdAt === undefined) && (missing8 = "createdAt"))) || ((data.updatedAt === undefined) && (missing8 = "updatedAt"))) || ((data.aiProvider === undefined) && (missing8 = "aiProvider"))) || ((data.aiModel === undefined) && (missing8 = "aiModel"))){
validate27.errors = [{instancePath,schemaPath:"#/required",keyword:"required",params:{missingProperty: missing8},message:"must have required property '"+missing8+"'"}];
return false;
}
else {
const _errs39 = errors;
for(const key0 in data){
if(!(func14.call(schema40.properties, key0))){
validate27.errors = [{instancePath,schemaPath:"#/additionalProperties",keyword:"additionalProperties",params:{additionalProperty: key0},message:"must NOT have additional properties"}];
return false;
break;
}
}
if(_errs39 === errors){
if(data.jobId !== undefined){
let data6 = data.jobId;
const _errs40 = errors;
if(errors === _errs40){
if(errors === _errs40){
if(typeof data6 === "string"){
if(!(formats6.test(data6))){
validate27.errors = [{instancePath:instancePath+"/jobId",schemaPath:"#/properties/jobId/format",keyword:"format",params:{format: "uuid"},message:"must match format \""+"uuid"+"\""}];
return false;
}
}
else {
validate27.errors = [{instancePath:instancePath+"/jobId",schemaPath:"#/properties/jobId/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
}
var valid17 = _errs40 === errors;
}
else {
var valid17 = true;
}
if(valid17){
if(data.youtubeVideoId !== undefined){
let data7 = data.youtubeVideoId;
const _errs42 = errors;
if(errors === _errs42){
if(typeof data7 === "string"){
if(!pattern7.test(data7)){
validate27.errors = [{instancePath:instancePath+"/youtubeVideoId",schemaPath:"#/properties/youtubeVideoId/pattern",keyword:"pattern",params:{pattern: "^[A-Za-z0-9_-]{11}$"},message:"must match pattern \""+"^[A-Za-z0-9_-]{11}$"+"\""}];
return false;
}
}
else {
validate27.errors = [{instancePath:instancePath+"/youtubeVideoId",schemaPath:"#/properties/youtubeVideoId/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid17 = _errs42 === errors;
}
else {
var valid17 = true;
}
if(valid17){
if(data.videoDurationSeconds !== undefined){
let data8 = data.videoDurationSeconds;
const _errs44 = errors;
if(!(((typeof data8 == "number") && (!(data8 % 1) && !isNaN(data8))) && (isFinite(data8)))){
validate27.errors = [{instancePath:instancePath+"/videoDurationSeconds",schemaPath:"#/properties/videoDurationSeconds/type",keyword:"type",params:{type: "integer"},message:"must be integer"}];
return false;
}
if(errors === _errs44){
if((typeof data8 == "number") && (isFinite(data8))){
if(data8 < 1 || isNaN(data8)){
validate27.errors = [{instancePath:instancePath+"/videoDurationSeconds",schemaPath:"#/properties/videoDurationSeconds/minimum",keyword:"minimum",params:{comparison: ">=", limit: 1},message:"must be >= 1"}];
return false;
}
}
}
var valid17 = _errs44 === errors;
}
else {
var valid17 = true;
}
if(valid17){
if(data.sourceLanguage !== undefined){
let data9 = data.sourceLanguage;
const _errs46 = errors;
if(typeof data9 !== "string"){
validate27.errors = [{instancePath:instancePath+"/sourceLanguage",schemaPath:"#/properties/sourceLanguage/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
if(!((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((data9 === "auto") || (data9 === "bel")) || (data9 === "bos")) || (data9 === "bul")) || (data9 === "cat")) || (data9 === "hrv")) || (data9 === "ces")) || (data9 === "dan")) || (data9 === "nld")) || (data9 === "eng")) || (data9 === "est")) || (data9 === "fin")) || (data9 === "fra")) || (data9 === "glg")) || (data9 === "deu")) || (data9 === "ell")) || (data9 === "hun")) || (data9 === "isl")) || (data9 === "ind")) || (data9 === "ita")) || (data9 === "jpn")) || (data9 === "kan")) || (data9 === "lav")) || (data9 === "mkd")) || (data9 === "msa")) || (data9 === "mal")) || (data9 === "nor")) || (data9 === "pol")) || (data9 === "por")) || (data9 === "ron")) || (data9 === "rus")) || (data9 === "slk")) || (data9 === "spa")) || (data9 === "swe")) || (data9 === "tur")) || (data9 === "ukr")) || (data9 === "vie")) || (data9 === "hye")) || (data9 === "aze")) || (data9 === "ben")) || (data9 === "yue")) || (data9 === "fil")) || (data9 === "kat")) || (data9 === "guj")) || (data9 === "hin")) || (data9 === "kaz")) || (data9 === "lit")) || (data9 === "mlt")) || (data9 === "cmn")) || (data9 === "mar")) || (data9 === "nep")) || (data9 === "ori")) || (data9 === "fas")) || (data9 === "srp")) || (data9 === "slv")) || (data9 === "swa")) || (data9 === "tam")) || (data9 === "tel")) || (data9 === "afr")) || (data9 === "ara")) || (data9 === "asm")) || (data9 === "ast")) || (data9 === "mya")) || (data9 === "hau")) || (data9 === "heb")) || (data9 === "jav")) || (data9 === "kor")) || (data9 === "kir")) || (data9 === "ltz")) || (data9 === "mri")) || (data9 === "oci")) || (data9 === "pan")) || (data9 === "tgk")) || (data9 === "tha")) || (data9 === "uzb")) || (data9 === "cym")) || (data9 === "amh")) || (data9 === "lug")) || (data9 === "ibo")) || (data9 === "gle")) || (data9 === "khm")) || (data9 === "kur")) || (data9 === "lao")) || (data9 === "mon")) || (data9 === "nso")) || (data9 === "pus")) || (data9 === "sna")) || (data9 === "snd")) || (data9 === "som")) || (data9 === "urd")) || (data9 === "wol")) || (data9 === "xho")) || (data9 === "yor")) || (data9 === "zul"))){
validate27.errors = [{instancePath:instancePath+"/sourceLanguage",schemaPath:"#/properties/sourceLanguage/enum",keyword:"enum",params:{allowedValues: schema40.properties.sourceLanguage.enum},message:"must be equal to one of the allowed values"}];
return false;
}
var valid17 = _errs46 === errors;
}
else {
var valid17 = true;
}
if(valid17){
if(data.targetLanguage !== undefined){
let data10 = data.targetLanguage;
const _errs48 = errors;
if(typeof data10 !== "string"){
validate27.errors = [{instancePath:instancePath+"/targetLanguage",schemaPath:"#/properties/targetLanguage/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
if(!(((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((data10 === "bel") || (data10 === "bos")) || (data10 === "bul")) || (data10 === "cat")) || (data10 === "hrv")) || (data10 === "ces")) || (data10 === "dan")) || (data10 === "nld")) || (data10 === "eng")) || (data10 === "est")) || (data10 === "fin")) || (data10 === "fra")) || (data10 === "glg")) || (data10 === "deu")) || (data10 === "ell")) || (data10 === "hun")) || (data10 === "isl")) || (data10 === "ind")) || (data10 === "ita")) || (data10 === "jpn")) || (data10 === "kan")) || (data10 === "lav")) || (data10 === "mkd")) || (data10 === "msa")) || (data10 === "mal")) || (data10 === "nor")) || (data10 === "pol")) || (data10 === "por")) || (data10 === "ron")) || (data10 === "rus")) || (data10 === "slk")) || (data10 === "spa")) || (data10 === "swe")) || (data10 === "tur")) || (data10 === "ukr")) || (data10 === "vie")) || (data10 === "hye")) || (data10 === "aze")) || (data10 === "ben")) || (data10 === "yue")) || (data10 === "fil")) || (data10 === "kat")) || (data10 === "guj")) || (data10 === "hin")) || (data10 === "kaz")) || (data10 === "lit")) || (data10 === "mlt")) || (data10 === "cmn")) || (data10 === "mar")) || (data10 === "nep")) || (data10 === "ori")) || (data10 === "fas")) || (data10 === "srp")) || (data10 === "slv")) || (data10 === "swa")) || (data10 === "tam")) || (data10 === "tel")) || (data10 === "afr")) || (data10 === "ara")) || (data10 === "asm")) || (data10 === "ast")) || (data10 === "mya")) || (data10 === "hau")) || (data10 === "heb")) || (data10 === "jav")) || (data10 === "kor")) || (data10 === "kir")) || (data10 === "ltz")) || (data10 === "mri")) || (data10 === "oci")) || (data10 === "pan")) || (data10 === "tgk")) || (data10 === "tha")) || (data10 === "uzb")) || (data10 === "cym")) || (data10 === "amh")) || (data10 === "lug")) || (data10 === "ibo")) || (data10 === "gle")) || (data10 === "khm")) || (data10 === "kur")) || (data10 === "lao")) || (data10 === "mon")) || (data10 === "nso")) || (data10 === "pus")) || (data10 === "sna")) || (data10 === "snd")) || (data10 === "som")) || (data10 === "urd")) || (data10 === "wol")) || (data10 === "xho")) || (data10 === "yor")) || (data10 === "zul"))){
validate27.errors = [{instancePath:instancePath+"/targetLanguage",schemaPath:"#/properties/targetLanguage/enum",keyword:"enum",params:{allowedValues: schema40.properties.targetLanguage.enum},message:"must be equal to one of the allowed values"}];
return false;
}
var valid17 = _errs48 === errors;
}
else {
var valid17 = true;
}
if(valid17){
if(data.includeRomanization !== undefined){
const _errs50 = errors;
if(typeof data.includeRomanization !== "boolean"){
validate27.errors = [{instancePath:instancePath+"/includeRomanization",schemaPath:"#/properties/includeRomanization/type",keyword:"type",params:{type: "boolean"},message:"must be boolean"}];
return false;
}
var valid17 = _errs50 === errors;
}
else {
var valid17 = true;
}
if(valid17){
if(data.includeTranslation !== undefined){
const _errs52 = errors;
if(typeof data.includeTranslation !== "boolean"){
validate27.errors = [{instancePath:instancePath+"/includeTranslation",schemaPath:"#/properties/includeTranslation/type",keyword:"type",params:{type: "boolean"},message:"must be boolean"}];
return false;
}
var valid17 = _errs52 === errors;
}
else {
var valid17 = true;
}
if(valid17){
if(data.status !== undefined){
let data13 = data.status;
const _errs54 = errors;
if(typeof data13 !== "string"){
validate27.errors = [{instancePath:instancePath+"/status",schemaPath:"#/properties/status/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
if(!(((((data13 === "queued") || (data13 === "running")) || (data13 === "completed")) || (data13 === "failed")) || (data13 === "cancelled"))){
validate27.errors = [{instancePath:instancePath+"/status",schemaPath:"#/properties/status/enum",keyword:"enum",params:{allowedValues: schema40.properties.status.enum},message:"must be equal to one of the allowed values"}];
return false;
}
var valid17 = _errs54 === errors;
}
else {
var valid17 = true;
}
if(valid17){
if(data.stage !== undefined){
let data14 = data.stage;
const _errs56 = errors;
if(typeof data14 !== "string"){
validate27.errors = [{instancePath:instancePath+"/stage",schemaPath:"#/properties/stage/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
if(!((((((((data14 === "preparing") || (data14 === "acquiring-audio")) || (data14 === "optimizing-audio")) || (data14 === "transcribing")) || (data14 === "tokenizing")) || (data14 === "romanizing")) || (data14 === "translating")) || (data14 === "finalizing"))){
validate27.errors = [{instancePath:instancePath+"/stage",schemaPath:"#/properties/stage/enum",keyword:"enum",params:{allowedValues: schema40.properties.stage.enum},message:"must be equal to one of the allowed values"}];
return false;
}
var valid17 = _errs56 === errors;
}
else {
var valid17 = true;
}
if(valid17){
if(data.progressPercent !== undefined){
let data15 = data.progressPercent;
const _errs58 = errors;
if(!(((typeof data15 == "number") && (!(data15 % 1) && !isNaN(data15))) && (isFinite(data15)))){
validate27.errors = [{instancePath:instancePath+"/progressPercent",schemaPath:"#/properties/progressPercent/type",keyword:"type",params:{type: "integer"},message:"must be integer"}];
return false;
}
if(errors === _errs58){
if((typeof data15 == "number") && (isFinite(data15))){
if(data15 > 100 || isNaN(data15)){
validate27.errors = [{instancePath:instancePath+"/progressPercent",schemaPath:"#/properties/progressPercent/maximum",keyword:"maximum",params:{comparison: "<=", limit: 100},message:"must be <= 100"}];
return false;
}
else {
if(data15 < 0 || isNaN(data15)){
validate27.errors = [{instancePath:instancePath+"/progressPercent",schemaPath:"#/properties/progressPercent/minimum",keyword:"minimum",params:{comparison: ">=", limit: 0},message:"must be >= 0"}];
return false;
}
}
}
}
var valid17 = _errs58 === errors;
}
else {
var valid17 = true;
}
if(valid17){
if(data.track !== undefined){
const _errs60 = errors;
if(!(validate28(data.track, {instancePath:instancePath+"/track",parentData:data,parentDataProperty:"track",rootData,dynamicAnchors}))){
vErrors = vErrors === null ? validate28.errors : vErrors.concat(validate28.errors);
errors = vErrors.length;
}
var valid17 = _errs60 === errors;
}
else {
var valid17 = true;
}
if(valid17){
if(data.createdAt !== undefined){
let data17 = data.createdAt;
const _errs61 = errors;
if(errors === _errs61){
if(errors === _errs61){
if(typeof data17 === "string"){
if(!(formats12.validate.test(data17))){
validate27.errors = [{instancePath:instancePath+"/createdAt",schemaPath:"#/properties/createdAt/format",keyword:"format",params:{format: "date-time"},message:"must match format \""+"date-time"+"\""}];
return false;
}
}
else {
validate27.errors = [{instancePath:instancePath+"/createdAt",schemaPath:"#/properties/createdAt/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
}
var valid17 = _errs61 === errors;
}
else {
var valid17 = true;
}
if(valid17){
if(data.updatedAt !== undefined){
let data18 = data.updatedAt;
const _errs63 = errors;
if(errors === _errs63){
if(errors === _errs63){
if(typeof data18 === "string"){
if(!(formats12.validate.test(data18))){
validate27.errors = [{instancePath:instancePath+"/updatedAt",schemaPath:"#/properties/updatedAt/format",keyword:"format",params:{format: "date-time"},message:"must match format \""+"date-time"+"\""}];
return false;
}
}
else {
validate27.errors = [{instancePath:instancePath+"/updatedAt",schemaPath:"#/properties/updatedAt/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
}
var valid17 = _errs63 === errors;
}
else {
var valid17 = true;
}
if(valid17){
if(data.expiresAt !== undefined){
let data19 = data.expiresAt;
const _errs65 = errors;
if((typeof data19 !== "string") && (data19 !== null)){
validate27.errors = [{instancePath:instancePath+"/expiresAt",schemaPath:"#/properties/expiresAt/type",keyword:"type",params:{type: schema40.properties.expiresAt.type},message:"must be string,null"}];
return false;
}
if(errors === _errs65){
if(errors === _errs65){
if(typeof data19 === "string"){
if(!(formats12.validate.test(data19))){
validate27.errors = [{instancePath:instancePath+"/expiresAt",schemaPath:"#/properties/expiresAt/format",keyword:"format",params:{format: "date-time"},message:"must match format \""+"date-time"+"\""}];
return false;
}
}
}
}
var valid17 = _errs65 === errors;
}
else {
var valid17 = true;
}
if(valid17){
if(data.detectedSourceLanguage !== undefined){
let data20 = data.detectedSourceLanguage;
const _errs67 = errors;
if(typeof data20 !== "string"){
validate27.errors = [{instancePath:instancePath+"/detectedSourceLanguage",schemaPath:"#/properties/detectedSourceLanguage/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
if(!(((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((data20 === "bel") || (data20 === "bos")) || (data20 === "bul")) || (data20 === "cat")) || (data20 === "hrv")) || (data20 === "ces")) || (data20 === "dan")) || (data20 === "nld")) || (data20 === "eng")) || (data20 === "est")) || (data20 === "fin")) || (data20 === "fra")) || (data20 === "glg")) || (data20 === "deu")) || (data20 === "ell")) || (data20 === "hun")) || (data20 === "isl")) || (data20 === "ind")) || (data20 === "ita")) || (data20 === "jpn")) || (data20 === "kan")) || (data20 === "lav")) || (data20 === "mkd")) || (data20 === "msa")) || (data20 === "mal")) || (data20 === "nor")) || (data20 === "pol")) || (data20 === "por")) || (data20 === "ron")) || (data20 === "rus")) || (data20 === "slk")) || (data20 === "spa")) || (data20 === "swe")) || (data20 === "tur")) || (data20 === "ukr")) || (data20 === "vie")) || (data20 === "hye")) || (data20 === "aze")) || (data20 === "ben")) || (data20 === "yue")) || (data20 === "fil")) || (data20 === "kat")) || (data20 === "guj")) || (data20 === "hin")) || (data20 === "kaz")) || (data20 === "lit")) || (data20 === "mlt")) || (data20 === "cmn")) || (data20 === "mar")) || (data20 === "nep")) || (data20 === "ori")) || (data20 === "fas")) || (data20 === "srp")) || (data20 === "slv")) || (data20 === "swa")) || (data20 === "tam")) || (data20 === "tel")) || (data20 === "afr")) || (data20 === "ara")) || (data20 === "asm")) || (data20 === "ast")) || (data20 === "mya")) || (data20 === "hau")) || (data20 === "heb")) || (data20 === "jav")) || (data20 === "kor")) || (data20 === "kir")) || (data20 === "ltz")) || (data20 === "mri")) || (data20 === "oci")) || (data20 === "pan")) || (data20 === "tgk")) || (data20 === "tha")) || (data20 === "uzb")) || (data20 === "cym")) || (data20 === "amh")) || (data20 === "lug")) || (data20 === "ibo")) || (data20 === "gle")) || (data20 === "khm")) || (data20 === "kur")) || (data20 === "lao")) || (data20 === "mon")) || (data20 === "nso")) || (data20 === "pus")) || (data20 === "sna")) || (data20 === "snd")) || (data20 === "som")) || (data20 === "urd")) || (data20 === "wol")) || (data20 === "xho")) || (data20 === "yor")) || (data20 === "zul"))){
validate27.errors = [{instancePath:instancePath+"/detectedSourceLanguage",schemaPath:"#/properties/detectedSourceLanguage/enum",keyword:"enum",params:{allowedValues: schema40.properties.detectedSourceLanguage.enum},message:"must be equal to one of the allowed values"}];
return false;
}
var valid17 = _errs67 === errors;
}
else {
var valid17 = true;
}
if(valid17){
if(data.message !== undefined){
let data21 = data.message;
const _errs69 = errors;
if(errors === _errs69){
if(typeof data21 === "string"){
if(func1(data21) < 1){
validate27.errors = [{instancePath:instancePath+"/message",schemaPath:"#/properties/message/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
}
else {
validate27.errors = [{instancePath:instancePath+"/message",schemaPath:"#/properties/message/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid17 = _errs69 === errors;
}
else {
var valid17 = true;
}
if(valid17){
if(data.errorCode !== undefined){
let data22 = data.errorCode;
const _errs71 = errors;
if(typeof data22 !== "string"){
validate27.errors = [{instancePath:instancePath+"/errorCode",schemaPath:"error-object.schema.json#/properties/code/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
if(!((((((((((((((((((((data22 === "validation_failed") || (data22 === "unauthorized")) || (data22 === "unsupported_video")) || (data22 === "audio_unavailable")) || (data22 === "audio_acquisition_failed")) || (data22 === "queue_publication_failed")) || (data22 === "generation_cancelled")) || (data22 === "generation_not_cancellable")) || (data22 === "transcription_failed")) || (data22 === "enrichment_failed")) || (data22 === "rate_limited")) || (data22 === "not_found")) || (data22 === "expired")) || (data22 === "lyrics_correction_in_progress")) || (data22 === "lyrics_incomplete")) || (data22 === "lyrics_do_not_match")) || (data22 === "lyrics_correction_failed")) || (data22 === "internal_error")) || (data22 === "provider_not_configured")) || (data22 === "provider_unavailable"))){
validate27.errors = [{instancePath:instancePath+"/errorCode",schemaPath:"error-object.schema.json#/properties/code/enum",keyword:"enum",params:{allowedValues: schema42.enum},message:"must be equal to one of the allowed values"}];
return false;
}
var valid17 = _errs71 === errors;
}
else {
var valid17 = true;
}
if(valid17){
if(data.aiProvider !== undefined){
let data23 = data.aiProvider;
const _errs74 = errors;
if(typeof data23 !== "string"){
validate27.errors = [{instancePath:instancePath+"/aiProvider",schemaPath:"#/properties/aiProvider/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
if(!(((((data23 === "openai") || (data23 === "cerebras")) || (data23 === "codex")) || (data23 === "claude")) || (data23 === "anthropic"))){
validate27.errors = [{instancePath:instancePath+"/aiProvider",schemaPath:"#/properties/aiProvider/enum",keyword:"enum",params:{allowedValues: schema40.properties.aiProvider.enum},message:"must be equal to one of the allowed values"}];
return false;
}
var valid17 = _errs74 === errors;
}
else {
var valid17 = true;
}
if(valid17){
if(data.aiModel !== undefined){
let data24 = data.aiModel;
const _errs76 = errors;
if(errors === _errs76){
if(typeof data24 === "string"){
if(func1(data24) > 128){
validate27.errors = [{instancePath:instancePath+"/aiModel",schemaPath:"#/properties/aiModel/maxLength",keyword:"maxLength",params:{limit: 128},message:"must NOT have more than 128 characters"}];
return false;
}
else {
if(func1(data24) < 1){
validate27.errors = [{instancePath:instancePath+"/aiModel",schemaPath:"#/properties/aiModel/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
}
}
else {
validate27.errors = [{instancePath:instancePath+"/aiModel",schemaPath:"#/properties/aiModel/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid17 = _errs76 === errors;
}
else {
var valid17 = true;
}
if(valid17){
if(data.partialTrack !== undefined){
const _errs78 = errors;
if(!(validate31(data.partialTrack, {instancePath:instancePath+"/partialTrack",parentData:data,parentDataProperty:"partialTrack",rootData,dynamicAnchors}))){
vErrors = vErrors === null ? validate31.errors : vErrors.concat(validate31.errors);
errors = vErrors.length;
}
var valid17 = _errs78 === errors;
}
else {
var valid17 = true;
}
if(valid17){
if(data.aiFastMode !== undefined){
const _errs79 = errors;
if(typeof data.aiFastMode !== "boolean"){
validate27.errors = [{instancePath:instancePath+"/aiFastMode",schemaPath:"#/properties/aiFastMode/type",keyword:"type",params:{type: "boolean"},message:"must be boolean"}];
return false;
}
var valid17 = _errs79 === errors;
}
else {
var valid17 = true;
}
}
}
}
}
}
}
}
}
}
}
}
}
}
}
}
}
}
}
}
}
}
}
}
else {
validate27.errors = [{instancePath,schemaPath:"#/type",keyword:"type",params:{type: "object"},message:"must be object"}];
return false;
}
}
validate27.errors = vErrors;
return errors === 0;
}
validate27.evaluated = {"props":true,"dynamicProps":false,"dynamicItems":false};

export const learningTokenRequest = validate33;
const schema45 = {"$schema":"https://json-schema.org/draft/2020-12/schema","$id":"https://transcribed-subtitle-extension.local/schemas/learning-token-request.schema.json","title":"LearningTokenRequest","type":"object","additionalProperties":false,"required":["trackId","cueId","tokenIndex"],"properties":{"trackId":{"type":"string","format":"uuid","description":"Generated subtitle track containing the clicked token."},"cueId":{"type":"string","minLength":1,"maxLength":64},"tokenIndex":{"type":"integer","minimum":0}}};

function validate33(data, {instancePath="", parentData, parentDataProperty, rootData=data, dynamicAnchors={}}={}){
/*# sourceURL="https://transcribed-subtitle-extension.local/schemas/learning-token-request.schema.json" */;
let vErrors = null;
let errors = 0;
const evaluated0 = validate33.evaluated;
if(evaluated0.dynamicProps){
evaluated0.props = undefined;
}
if(evaluated0.dynamicItems){
evaluated0.items = undefined;
}
if(errors === 0){
if(data && typeof data == "object" && !Array.isArray(data)){
let missing0;
if((((data.trackId === undefined) && (missing0 = "trackId")) || ((data.cueId === undefined) && (missing0 = "cueId"))) || ((data.tokenIndex === undefined) && (missing0 = "tokenIndex"))){
validate33.errors = [{instancePath,schemaPath:"#/required",keyword:"required",params:{missingProperty: missing0},message:"must have required property '"+missing0+"'"}];
return false;
}
else {
const _errs1 = errors;
for(const key0 in data){
if(!(((key0 === "trackId") || (key0 === "cueId")) || (key0 === "tokenIndex"))){
validate33.errors = [{instancePath,schemaPath:"#/additionalProperties",keyword:"additionalProperties",params:{additionalProperty: key0},message:"must NOT have additional properties"}];
return false;
break;
}
}
if(_errs1 === errors){
if(data.trackId !== undefined){
let data0 = data.trackId;
const _errs2 = errors;
if(errors === _errs2){
if(errors === _errs2){
if(typeof data0 === "string"){
if(!(formats6.test(data0))){
validate33.errors = [{instancePath:instancePath+"/trackId",schemaPath:"#/properties/trackId/format",keyword:"format",params:{format: "uuid"},message:"must match format \""+"uuid"+"\""}];
return false;
}
}
else {
validate33.errors = [{instancePath:instancePath+"/trackId",schemaPath:"#/properties/trackId/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
}
var valid0 = _errs2 === errors;
}
else {
var valid0 = true;
}
if(valid0){
if(data.cueId !== undefined){
let data1 = data.cueId;
const _errs4 = errors;
if(errors === _errs4){
if(typeof data1 === "string"){
if(func1(data1) > 64){
validate33.errors = [{instancePath:instancePath+"/cueId",schemaPath:"#/properties/cueId/maxLength",keyword:"maxLength",params:{limit: 64},message:"must NOT have more than 64 characters"}];
return false;
}
else {
if(func1(data1) < 1){
validate33.errors = [{instancePath:instancePath+"/cueId",schemaPath:"#/properties/cueId/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
}
}
else {
validate33.errors = [{instancePath:instancePath+"/cueId",schemaPath:"#/properties/cueId/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid0 = _errs4 === errors;
}
else {
var valid0 = true;
}
if(valid0){
if(data.tokenIndex !== undefined){
let data2 = data.tokenIndex;
const _errs6 = errors;
if(!(((typeof data2 == "number") && (!(data2 % 1) && !isNaN(data2))) && (isFinite(data2)))){
validate33.errors = [{instancePath:instancePath+"/tokenIndex",schemaPath:"#/properties/tokenIndex/type",keyword:"type",params:{type: "integer"},message:"must be integer"}];
return false;
}
if(errors === _errs6){
if((typeof data2 == "number") && (isFinite(data2))){
if(data2 < 0 || isNaN(data2)){
validate33.errors = [{instancePath:instancePath+"/tokenIndex",schemaPath:"#/properties/tokenIndex/minimum",keyword:"minimum",params:{comparison: ">=", limit: 0},message:"must be >= 0"}];
return false;
}
}
}
var valid0 = _errs6 === errors;
}
else {
var valid0 = true;
}
}
}
}
}
}
else {
validate33.errors = [{instancePath,schemaPath:"#/type",keyword:"type",params:{type: "object"},message:"must be object"}];
return false;
}
}
validate33.errors = vErrors;
return errors === 0;
}
validate33.evaluated = {"props":true,"dynamicProps":false,"dynamicItems":false};

export const learningTokenResponse = validate34;
const schema46 = {"$schema":"https://json-schema.org/draft/2020-12/schema","$id":"https://transcribed-subtitle-extension.local/schemas/learning-token-response.schema.json","title":"LearningTokenResponse","type":"object","additionalProperties":false,"required":["trackId","cueId","token"],"properties":{"trackId":{"type":"string","format":"uuid"},"cueId":{"type":"string","minLength":1},"token":{"$ref":"token.schema.json"}}};

function validate34(data, {instancePath="", parentData, parentDataProperty, rootData=data, dynamicAnchors={}}={}){
/*# sourceURL="https://transcribed-subtitle-extension.local/schemas/learning-token-response.schema.json" */;
let vErrors = null;
let errors = 0;
const evaluated0 = validate34.evaluated;
if(evaluated0.dynamicProps){
evaluated0.props = undefined;
}
if(evaluated0.dynamicItems){
evaluated0.items = undefined;
}
if(errors === 0){
if(data && typeof data == "object" && !Array.isArray(data)){
let missing0;
if((((data.trackId === undefined) && (missing0 = "trackId")) || ((data.cueId === undefined) && (missing0 = "cueId"))) || ((data.token === undefined) && (missing0 = "token"))){
validate34.errors = [{instancePath,schemaPath:"#/required",keyword:"required",params:{missingProperty: missing0},message:"must have required property '"+missing0+"'"}];
return false;
}
else {
const _errs1 = errors;
for(const key0 in data){
if(!(((key0 === "trackId") || (key0 === "cueId")) || (key0 === "token"))){
validate34.errors = [{instancePath,schemaPath:"#/additionalProperties",keyword:"additionalProperties",params:{additionalProperty: key0},message:"must NOT have additional properties"}];
return false;
break;
}
}
if(_errs1 === errors){
if(data.trackId !== undefined){
let data0 = data.trackId;
const _errs2 = errors;
if(errors === _errs2){
if(errors === _errs2){
if(typeof data0 === "string"){
if(!(formats6.test(data0))){
validate34.errors = [{instancePath:instancePath+"/trackId",schemaPath:"#/properties/trackId/format",keyword:"format",params:{format: "uuid"},message:"must match format \""+"uuid"+"\""}];
return false;
}
}
else {
validate34.errors = [{instancePath:instancePath+"/trackId",schemaPath:"#/properties/trackId/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
}
var valid0 = _errs2 === errors;
}
else {
var valid0 = true;
}
if(valid0){
if(data.cueId !== undefined){
let data1 = data.cueId;
const _errs4 = errors;
if(errors === _errs4){
if(typeof data1 === "string"){
if(func1(data1) < 1){
validate34.errors = [{instancePath:instancePath+"/cueId",schemaPath:"#/properties/cueId/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
}
else {
validate34.errors = [{instancePath:instancePath+"/cueId",schemaPath:"#/properties/cueId/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid0 = _errs4 === errors;
}
else {
var valid0 = true;
}
if(valid0){
if(data.token !== undefined){
let data2 = data.token;
const _errs6 = errors;
const _errs7 = errors;
if(errors === _errs7){
if(data2 && typeof data2 == "object" && !Array.isArray(data2)){
let missing1;
if((((data2.index === undefined) && (missing1 = "index")) || ((data2.text === undefined) && (missing1 = "text"))) || ((data2.normalizedText === undefined) && (missing1 = "normalizedText"))){
validate34.errors = [{instancePath:instancePath+"/token",schemaPath:"token.schema.json/required",keyword:"required",params:{missingProperty: missing1},message:"must have required property '"+missing1+"'"}];
return false;
}
else {
const _errs9 = errors;
for(const key1 in data2){
if(!(func14.call(schema37.properties, key1))){
validate34.errors = [{instancePath:instancePath+"/token",schemaPath:"token.schema.json/additionalProperties",keyword:"additionalProperties",params:{additionalProperty: key1},message:"must NOT have additional properties"}];
return false;
break;
}
}
if(_errs9 === errors){
if(data2.index !== undefined){
let data3 = data2.index;
const _errs10 = errors;
if(!(((typeof data3 == "number") && (!(data3 % 1) && !isNaN(data3))) && (isFinite(data3)))){
validate34.errors = [{instancePath:instancePath+"/token/index",schemaPath:"token.schema.json/properties/index/type",keyword:"type",params:{type: "integer"},message:"must be integer"}];
return false;
}
if(errors === _errs10){
if((typeof data3 == "number") && (isFinite(data3))){
if(data3 < 0 || isNaN(data3)){
validate34.errors = [{instancePath:instancePath+"/token/index",schemaPath:"token.schema.json/properties/index/minimum",keyword:"minimum",params:{comparison: ">=", limit: 0},message:"must be >= 0"}];
return false;
}
}
}
var valid2 = _errs10 === errors;
}
else {
var valid2 = true;
}
if(valid2){
if(data2.text !== undefined){
let data4 = data2.text;
const _errs12 = errors;
if(errors === _errs12){
if(typeof data4 === "string"){
if(func1(data4) < 1){
validate34.errors = [{instancePath:instancePath+"/token/text",schemaPath:"token.schema.json/properties/text/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
}
else {
validate34.errors = [{instancePath:instancePath+"/token/text",schemaPath:"token.schema.json/properties/text/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid2 = _errs12 === errors;
}
else {
var valid2 = true;
}
if(valid2){
if(data2.normalizedText !== undefined){
let data5 = data2.normalizedText;
const _errs14 = errors;
if(errors === _errs14){
if(typeof data5 === "string"){
if(func1(data5) < 1){
validate34.errors = [{instancePath:instancePath+"/token/normalizedText",schemaPath:"token.schema.json/properties/normalizedText/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
}
else {
validate34.errors = [{instancePath:instancePath+"/token/normalizedText",schemaPath:"token.schema.json/properties/normalizedText/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid2 = _errs14 === errors;
}
else {
var valid2 = true;
}
if(valid2){
if(data2.lemma !== undefined){
let data6 = data2.lemma;
const _errs16 = errors;
if(errors === _errs16){
if(typeof data6 === "string"){
if(func1(data6) < 1){
validate34.errors = [{instancePath:instancePath+"/token/lemma",schemaPath:"token.schema.json/properties/lemma/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
}
else {
validate34.errors = [{instancePath:instancePath+"/token/lemma",schemaPath:"token.schema.json/properties/lemma/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid2 = _errs16 === errors;
}
else {
var valid2 = true;
}
if(valid2){
if(data2.root !== undefined){
let data7 = data2.root;
const _errs18 = errors;
if(errors === _errs18){
if(typeof data7 === "string"){
if(func1(data7) < 1){
validate34.errors = [{instancePath:instancePath+"/token/root",schemaPath:"token.schema.json/properties/root/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
}
else {
validate34.errors = [{instancePath:instancePath+"/token/root",schemaPath:"token.schema.json/properties/root/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid2 = _errs18 === errors;
}
else {
var valid2 = true;
}
if(valid2){
if(data2.partOfSpeech !== undefined){
let data8 = data2.partOfSpeech;
const _errs20 = errors;
if(errors === _errs20){
if(typeof data8 === "string"){
if(func1(data8) < 1){
validate34.errors = [{instancePath:instancePath+"/token/partOfSpeech",schemaPath:"token.schema.json/properties/partOfSpeech/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
}
else {
validate34.errors = [{instancePath:instancePath+"/token/partOfSpeech",schemaPath:"token.schema.json/properties/partOfSpeech/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid2 = _errs20 === errors;
}
else {
var valid2 = true;
}
if(valid2){
if(data2.translation !== undefined){
let data9 = data2.translation;
const _errs22 = errors;
if(errors === _errs22){
if(typeof data9 === "string"){
if(func1(data9) < 1){
validate34.errors = [{instancePath:instancePath+"/token/translation",schemaPath:"token.schema.json/properties/translation/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
}
else {
validate34.errors = [{instancePath:instancePath+"/token/translation",schemaPath:"token.schema.json/properties/translation/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid2 = _errs22 === errors;
}
else {
var valid2 = true;
}
if(valid2){
if(data2.gloss !== undefined){
let data10 = data2.gloss;
const _errs24 = errors;
if(errors === _errs24){
if(typeof data10 === "string"){
if(func1(data10) < 1){
validate34.errors = [{instancePath:instancePath+"/token/gloss",schemaPath:"token.schema.json/properties/gloss/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
}
else {
validate34.errors = [{instancePath:instancePath+"/token/gloss",schemaPath:"token.schema.json/properties/gloss/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid2 = _errs24 === errors;
}
else {
var valid2 = true;
}
if(valid2){
if(data2.romanization !== undefined){
let data11 = data2.romanization;
const _errs26 = errors;
if(errors === _errs26){
if(typeof data11 === "string"){
if(func1(data11) < 1){
validate34.errors = [{instancePath:instancePath+"/token/romanization",schemaPath:"token.schema.json/properties/romanization/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
}
else {
validate34.errors = [{instancePath:instancePath+"/token/romanization",schemaPath:"token.schema.json/properties/romanization/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid2 = _errs26 === errors;
}
else {
var valid2 = true;
}
if(valid2){
if(data2.usageNote !== undefined){
let data12 = data2.usageNote;
const _errs28 = errors;
if(errors === _errs28){
if(typeof data12 === "string"){
if(func1(data12) < 1){
validate34.errors = [{instancePath:instancePath+"/token/usageNote",schemaPath:"token.schema.json/properties/usageNote/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
}
else {
validate34.errors = [{instancePath:instancePath+"/token/usageNote",schemaPath:"token.schema.json/properties/usageNote/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid2 = _errs28 === errors;
}
else {
var valid2 = true;
}
}
}
}
}
}
}
}
}
}
}
}
}
else {
validate34.errors = [{instancePath:instancePath+"/token",schemaPath:"token.schema.json/type",keyword:"type",params:{type: "object"},message:"must be object"}];
return false;
}
}
var valid0 = _errs6 === errors;
}
else {
var valid0 = true;
}
}
}
}
}
}
else {
validate34.errors = [{instancePath,schemaPath:"#/type",keyword:"type",params:{type: "object"},message:"must be object"}];
return false;
}
}
validate34.errors = vErrors;
return errors === 0;
}
validate34.evaluated = {"props":true,"dynamicProps":false,"dynamicItems":false};

export const lyricsCorrectionCancelRequest = validate35;
const schema48 = {"$schema":"https://json-schema.org/draft/2020-12/schema","$id":"https://transcribed-subtitle-extension.local/schemas/lyrics-correction-cancel-request.schema.json","title":"LyricsCorrectionCancelRequest","type":"object","additionalProperties":false,"required":["attemptId"],"properties":{"attemptId":{"type":"string","format":"uuid"}}};

function validate35(data, {instancePath="", parentData, parentDataProperty, rootData=data, dynamicAnchors={}}={}){
/*# sourceURL="https://transcribed-subtitle-extension.local/schemas/lyrics-correction-cancel-request.schema.json" */;
let vErrors = null;
let errors = 0;
const evaluated0 = validate35.evaluated;
if(evaluated0.dynamicProps){
evaluated0.props = undefined;
}
if(evaluated0.dynamicItems){
evaluated0.items = undefined;
}
if(errors === 0){
if(data && typeof data == "object" && !Array.isArray(data)){
let missing0;
if((data.attemptId === undefined) && (missing0 = "attemptId")){
validate35.errors = [{instancePath,schemaPath:"#/required",keyword:"required",params:{missingProperty: missing0},message:"must have required property '"+missing0+"'"}];
return false;
}
else {
const _errs1 = errors;
for(const key0 in data){
if(!(key0 === "attemptId")){
validate35.errors = [{instancePath,schemaPath:"#/additionalProperties",keyword:"additionalProperties",params:{additionalProperty: key0},message:"must NOT have additional properties"}];
return false;
break;
}
}
if(_errs1 === errors){
if(data.attemptId !== undefined){
let data0 = data.attemptId;
const _errs2 = errors;
if(errors === _errs2){
if(errors === _errs2){
if(typeof data0 === "string"){
if(!(formats6.test(data0))){
validate35.errors = [{instancePath:instancePath+"/attemptId",schemaPath:"#/properties/attemptId/format",keyword:"format",params:{format: "uuid"},message:"must match format \""+"uuid"+"\""}];
return false;
}
}
else {
validate35.errors = [{instancePath:instancePath+"/attemptId",schemaPath:"#/properties/attemptId/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
}
}
}
}
}
else {
validate35.errors = [{instancePath,schemaPath:"#/type",keyword:"type",params:{type: "object"},message:"must be object"}];
return false;
}
}
validate35.errors = vErrors;
return errors === 0;
}
validate35.evaluated = {"props":true,"dynamicProps":false,"dynamicItems":false};

export const lyricsCorrectionRequest = validate36;
const schema49 = {"$schema":"https://json-schema.org/draft/2020-12/schema","$id":"https://transcribed-subtitle-extension.local/schemas/lyrics-correction-request.schema.json","title":"LyricsCorrectionRequest","type":"object","additionalProperties":false,"required":["expectedTrackId","lyrics"],"properties":{"expectedTrackId":{"type":"string","format":"uuid","description":"Identity of the track the learner intends to replace."},"lyrics":{"type":"string","minLength":1,"maxLength":25000,"description":"Pasted plain-text lyrics, at most 25,000 raw Unicode code points. The backend requires a Unicode letter, rejects C0/C1 controls except tab/LF/CR, and rejects HTTP(S)/www link-only input before whitespace normalization."},"aiProvider":{"type":"string","enum":["openai","cerebras","codex","claude","anthropic"],"description":"Optional provider override for this correction only. Omit all AI fields to inherit the generation’s saved provider, model, and fast mode."},"aiModel":{"type":"string","minLength":1,"maxLength":128,"pattern":"^[A-Za-z0-9][A-Za-z0-9._-]*$","description":"Required for Codex (a model from the connected account), Claude subscription and Claude API (models listed below); omitted for other providers."},"aiFastMode":{"type":"boolean","default":false,"description":"Codex fast service tier, or Claude API fast mode on Opus 5.5, Opus 5 and Opus 4.8. Omitted for other providers."}},"allOf":[{"if":{"required":["aiProvider"],"properties":{"aiProvider":{"enum":["codex","claude","anthropic"]}}},"then":{"required":["aiModel"]},"else":{"not":{"required":["aiModel"]}}},{"if":{"required":["aiProvider"],"properties":{"aiProvider":{"enum":["codex","anthropic"]}}},"else":{"properties":{"aiFastMode":{"const":false}}}},{"if":{"required":["aiProvider"],"properties":{"aiProvider":{"const":"claude"}}},"then":{"properties":{"aiModel":{"enum":["opus","sonnet","haiku","claude-fable-5-1","claude-opus-5","claude-opus-4-8","claude-sonnet-5"]}}}},{"if":{"required":["aiProvider"],"properties":{"aiProvider":{"const":"anthropic"}}},"then":{"properties":{"aiModel":{"enum":["claude-opus-5-5","claude-sonnet-5-5","claude-haiku-5-5","claude-fable-5-1","claude-opus-5","claude-opus-4-8","claude-sonnet-5"]}}}},{"if":{"required":["aiProvider","aiFastMode"],"properties":{"aiProvider":{"const":"anthropic"},"aiFastMode":{"const":true}}},"then":{"properties":{"aiModel":{"enum":["claude-opus-5-5","claude-opus-5","claude-opus-4-8"]}}}}]};

function validate36(data, {instancePath="", parentData, parentDataProperty, rootData=data, dynamicAnchors={}}={}){
/*# sourceURL="https://transcribed-subtitle-extension.local/schemas/lyrics-correction-request.schema.json" */;
let vErrors = null;
let errors = 0;
const evaluated0 = validate36.evaluated;
if(evaluated0.dynamicProps){
evaluated0.props = undefined;
}
if(evaluated0.dynamicItems){
evaluated0.items = undefined;
}
const _errs1 = errors;
const _errs2 = errors;
let valid1 = true;
const _errs3 = errors;
if(data && typeof data == "object" && !Array.isArray(data)){
let missing0;
if((data.aiProvider === undefined) && (missing0 = "aiProvider")){
const err0 = {};
if(vErrors === null){
vErrors = [err0];
}
else {
vErrors.push(err0);
}
errors++;
}
else {
if(data.aiProvider !== undefined){
let data0 = data.aiProvider;
if(!(((data0 === "codex") || (data0 === "claude")) || (data0 === "anthropic"))){
const err1 = {};
if(vErrors === null){
vErrors = [err1];
}
else {
vErrors.push(err1);
}
errors++;
}
}
}
}
var _valid0 = _errs3 === errors;
errors = _errs2;
if(vErrors !== null){
if(_errs2){
vErrors.length = _errs2;
}
else {
vErrors = null;
}
}
let ifClause0;
if(_valid0){
const _errs5 = errors;
if(data && typeof data == "object" && !Array.isArray(data)){
let missing1;
if((data.aiModel === undefined) && (missing1 = "aiModel")){
validate36.errors = [{instancePath,schemaPath:"#/allOf/0/then/required",keyword:"required",params:{missingProperty: missing1},message:"must have required property '"+missing1+"'"}];
return false;
}
}
var _valid0 = _errs5 === errors;
valid1 = _valid0;
ifClause0 = "then";
}
else {
const _errs6 = errors;
const _errs7 = errors;
const _errs8 = errors;
if(data && typeof data == "object" && !Array.isArray(data)){
let missing2;
if((data.aiModel === undefined) && (missing2 = "aiModel")){
const err2 = {};
if(vErrors === null){
vErrors = [err2];
}
else {
vErrors.push(err2);
}
errors++;
}
}
var valid3 = _errs8 === errors;
if(valid3){
validate36.errors = [{instancePath,schemaPath:"#/allOf/0/else/not",keyword:"not",params:{},message:"must NOT be valid"}];
return false;
}
else {
errors = _errs7;
if(vErrors !== null){
if(_errs7){
vErrors.length = _errs7;
}
else {
vErrors = null;
}
}
}
var _valid0 = _errs6 === errors;
valid1 = _valid0;
ifClause0 = "else";
}
if(!valid1){
const err3 = {instancePath,schemaPath:"#/allOf/0/if",keyword:"if",params:{failingKeyword: ifClause0},message:"must match \""+ifClause0+"\" schema"};
if(vErrors === null){
vErrors = [err3];
}
else {
vErrors.push(err3);
}
errors++;
validate36.errors = vErrors;
return false;
}
var valid0 = _errs1 === errors;
if(valid0){
const _errs9 = errors;
const _errs10 = errors;
let valid4 = true;
const _errs11 = errors;
if(data && typeof data == "object" && !Array.isArray(data)){
let missing3;
if((data.aiProvider === undefined) && (missing3 = "aiProvider")){
const err4 = {};
if(vErrors === null){
vErrors = [err4];
}
else {
vErrors.push(err4);
}
errors++;
}
else {
if(data.aiProvider !== undefined){
let data1 = data.aiProvider;
if(!((data1 === "codex") || (data1 === "anthropic"))){
const err5 = {};
if(vErrors === null){
vErrors = [err5];
}
else {
vErrors.push(err5);
}
errors++;
}
}
}
}
var _valid1 = _errs11 === errors;
errors = _errs10;
if(vErrors !== null){
if(_errs10){
vErrors.length = _errs10;
}
else {
vErrors = null;
}
}
if(!_valid1){
const _errs13 = errors;
if(data && typeof data == "object" && !Array.isArray(data)){
if(data.aiFastMode !== undefined){
if(false !== data.aiFastMode){
validate36.errors = [{instancePath:instancePath+"/aiFastMode",schemaPath:"#/allOf/1/else/properties/aiFastMode/const",keyword:"const",params:{allowedValue: false},message:"must be equal to constant"}];
return false;
}
}
}
var _valid1 = _errs13 === errors;
valid4 = _valid1;
if(valid4){
var props0 = {};
props0.aiFastMode = true;
props0.aiProvider = true;
}
}
if(!valid4){
const err6 = {instancePath,schemaPath:"#/allOf/1/if",keyword:"if",params:{failingKeyword: "else"},message:"must match \"else\" schema"};
if(vErrors === null){
vErrors = [err6];
}
else {
vErrors.push(err6);
}
errors++;
validate36.errors = vErrors;
return false;
}
var valid0 = _errs9 === errors;
if(valid0){
if(props0 !== true){
props0 = props0 || {};
props0.aiProvider = true;
}
const _errs15 = errors;
const _errs16 = errors;
let valid7 = true;
const _errs17 = errors;
if(data && typeof data == "object" && !Array.isArray(data)){
let missing4;
if((data.aiProvider === undefined) && (missing4 = "aiProvider")){
const err7 = {};
if(vErrors === null){
vErrors = [err7];
}
else {
vErrors.push(err7);
}
errors++;
}
else {
if(data.aiProvider !== undefined){
if("claude" !== data.aiProvider){
const err8 = {};
if(vErrors === null){
vErrors = [err8];
}
else {
vErrors.push(err8);
}
errors++;
}
}
}
}
var _valid2 = _errs17 === errors;
errors = _errs16;
if(vErrors !== null){
if(_errs16){
vErrors.length = _errs16;
}
else {
vErrors = null;
}
}
if(_valid2){
const _errs19 = errors;
if(data && typeof data == "object" && !Array.isArray(data)){
if(data.aiModel !== undefined){
let data4 = data.aiModel;
if(!(((((((data4 === "opus") || (data4 === "sonnet")) || (data4 === "haiku")) || (data4 === "claude-fable-5-1")) || (data4 === "claude-opus-5")) || (data4 === "claude-opus-4-8")) || (data4 === "claude-sonnet-5"))){
validate36.errors = [{instancePath:instancePath+"/aiModel",schemaPath:"#/allOf/2/then/properties/aiModel/enum",keyword:"enum",params:{allowedValues: schema49.allOf[2].then.properties.aiModel.enum},message:"must be equal to one of the allowed values"}];
return false;
}
}
}
var _valid2 = _errs19 === errors;
valid7 = _valid2;
if(valid7){
var props1 = {};
props1.aiModel = true;
props1.aiProvider = true;
}
}
if(!valid7){
const err9 = {instancePath,schemaPath:"#/allOf/2/if",keyword:"if",params:{failingKeyword: "then"},message:"must match \"then\" schema"};
if(vErrors === null){
vErrors = [err9];
}
else {
vErrors.push(err9);
}
errors++;
validate36.errors = vErrors;
return false;
}
var valid0 = _errs15 === errors;
if(valid0){
if(props0 !== true && props1 !== undefined){
if(props1 === true){
props0 = true;
}
else {
props0 = props0 || {};
Object.assign(props0, props1);
}
}
const _errs21 = errors;
const _errs22 = errors;
let valid10 = true;
const _errs23 = errors;
if(data && typeof data == "object" && !Array.isArray(data)){
let missing5;
if((data.aiProvider === undefined) && (missing5 = "aiProvider")){
const err10 = {};
if(vErrors === null){
vErrors = [err10];
}
else {
vErrors.push(err10);
}
errors++;
}
else {
if(data.aiProvider !== undefined){
if("anthropic" !== data.aiProvider){
const err11 = {};
if(vErrors === null){
vErrors = [err11];
}
else {
vErrors.push(err11);
}
errors++;
}
}
}
}
var _valid3 = _errs23 === errors;
errors = _errs22;
if(vErrors !== null){
if(_errs22){
vErrors.length = _errs22;
}
else {
vErrors = null;
}
}
if(_valid3){
const _errs25 = errors;
if(data && typeof data == "object" && !Array.isArray(data)){
if(data.aiModel !== undefined){
let data6 = data.aiModel;
if(!(((((((data6 === "claude-opus-5-5") || (data6 === "claude-sonnet-5-5")) || (data6 === "claude-haiku-5-5")) || (data6 === "claude-fable-5-1")) || (data6 === "claude-opus-5")) || (data6 === "claude-opus-4-8")) || (data6 === "claude-sonnet-5"))){
validate36.errors = [{instancePath:instancePath+"/aiModel",schemaPath:"#/allOf/3/then/properties/aiModel/enum",keyword:"enum",params:{allowedValues: schema49.allOf[3].then.properties.aiModel.enum},message:"must be equal to one of the allowed values"}];
return false;
}
}
}
var _valid3 = _errs25 === errors;
valid10 = _valid3;
if(valid10){
var props2 = {};
props2.aiModel = true;
props2.aiProvider = true;
}
}
if(!valid10){
const err12 = {instancePath,schemaPath:"#/allOf/3/if",keyword:"if",params:{failingKeyword: "then"},message:"must match \"then\" schema"};
if(vErrors === null){
vErrors = [err12];
}
else {
vErrors.push(err12);
}
errors++;
validate36.errors = vErrors;
return false;
}
var valid0 = _errs21 === errors;
if(valid0){
if(props0 !== true && props2 !== undefined){
if(props2 === true){
props0 = true;
}
else {
props0 = props0 || {};
Object.assign(props0, props2);
}
}
const _errs27 = errors;
const _errs28 = errors;
let valid13 = true;
const _errs29 = errors;
if(data && typeof data == "object" && !Array.isArray(data)){
let missing6;
if(((data.aiProvider === undefined) && (missing6 = "aiProvider")) || ((data.aiFastMode === undefined) && (missing6 = "aiFastMode"))){
const err13 = {};
if(vErrors === null){
vErrors = [err13];
}
else {
vErrors.push(err13);
}
errors++;
}
else {
if(data.aiProvider !== undefined){
const _errs30 = errors;
if("anthropic" !== data.aiProvider){
const err14 = {};
if(vErrors === null){
vErrors = [err14];
}
else {
vErrors.push(err14);
}
errors++;
}
var valid14 = _errs30 === errors;
}
else {
var valid14 = true;
}
if(valid14){
if(data.aiFastMode !== undefined){
const _errs31 = errors;
if(true !== data.aiFastMode){
const err15 = {};
if(vErrors === null){
vErrors = [err15];
}
else {
vErrors.push(err15);
}
errors++;
}
var valid14 = _errs31 === errors;
}
else {
var valid14 = true;
}
}
}
}
var _valid4 = _errs29 === errors;
errors = _errs28;
if(vErrors !== null){
if(_errs28){
vErrors.length = _errs28;
}
else {
vErrors = null;
}
}
if(_valid4){
const _errs32 = errors;
if(data && typeof data == "object" && !Array.isArray(data)){
if(data.aiModel !== undefined){
let data9 = data.aiModel;
if(!(((data9 === "claude-opus-5-5") || (data9 === "claude-opus-5")) || (data9 === "claude-opus-4-8"))){
validate36.errors = [{instancePath:instancePath+"/aiModel",schemaPath:"#/allOf/4/then/properties/aiModel/enum",keyword:"enum",params:{allowedValues: schema49.allOf[4].then.properties.aiModel.enum},message:"must be equal to one of the allowed values"}];
return false;
}
}
}
var _valid4 = _errs32 === errors;
valid13 = _valid4;
if(valid13){
var props3 = {};
props3.aiModel = true;
props3.aiProvider = true;
props3.aiFastMode = true;
}
}
if(!valid13){
const err16 = {instancePath,schemaPath:"#/allOf/4/if",keyword:"if",params:{failingKeyword: "then"},message:"must match \"then\" schema"};
if(vErrors === null){
vErrors = [err16];
}
else {
vErrors.push(err16);
}
errors++;
validate36.errors = vErrors;
return false;
}
var valid0 = _errs27 === errors;
if(valid0){
if(props0 !== true && props3 !== undefined){
if(props3 === true){
props0 = true;
}
else {
props0 = props0 || {};
Object.assign(props0, props3);
}
}
}
}
}
}
}
if(errors === 0){
if(data && typeof data == "object" && !Array.isArray(data)){
let missing7;
if(((data.expectedTrackId === undefined) && (missing7 = "expectedTrackId")) || ((data.lyrics === undefined) && (missing7 = "lyrics"))){
validate36.errors = [{instancePath,schemaPath:"#/required",keyword:"required",params:{missingProperty: missing7},message:"must have required property '"+missing7+"'"}];
return false;
}
else {
const _errs34 = errors;
for(const key0 in data){
if(!(((((key0 === "expectedTrackId") || (key0 === "lyrics")) || (key0 === "aiProvider")) || (key0 === "aiModel")) || (key0 === "aiFastMode"))){
validate36.errors = [{instancePath,schemaPath:"#/additionalProperties",keyword:"additionalProperties",params:{additionalProperty: key0},message:"must NOT have additional properties"}];
return false;
break;
}
}
if(_errs34 === errors){
if(data.expectedTrackId !== undefined){
let data10 = data.expectedTrackId;
const _errs35 = errors;
if(errors === _errs35){
if(errors === _errs35){
if(typeof data10 === "string"){
if(!(formats6.test(data10))){
validate36.errors = [{instancePath:instancePath+"/expectedTrackId",schemaPath:"#/properties/expectedTrackId/format",keyword:"format",params:{format: "uuid"},message:"must match format \""+"uuid"+"\""}];
return false;
}
}
else {
validate36.errors = [{instancePath:instancePath+"/expectedTrackId",schemaPath:"#/properties/expectedTrackId/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
}
var valid16 = _errs35 === errors;
}
else {
var valid16 = true;
}
if(valid16){
if(data.lyrics !== undefined){
let data11 = data.lyrics;
const _errs37 = errors;
if(errors === _errs37){
if(typeof data11 === "string"){
if(func1(data11) > 25000){
validate36.errors = [{instancePath:instancePath+"/lyrics",schemaPath:"#/properties/lyrics/maxLength",keyword:"maxLength",params:{limit: 25000},message:"must NOT have more than 25000 characters"}];
return false;
}
else {
if(func1(data11) < 1){
validate36.errors = [{instancePath:instancePath+"/lyrics",schemaPath:"#/properties/lyrics/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
}
}
else {
validate36.errors = [{instancePath:instancePath+"/lyrics",schemaPath:"#/properties/lyrics/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid16 = _errs37 === errors;
}
else {
var valid16 = true;
}
if(valid16){
if(data.aiProvider !== undefined){
let data12 = data.aiProvider;
const _errs39 = errors;
if(typeof data12 !== "string"){
validate36.errors = [{instancePath:instancePath+"/aiProvider",schemaPath:"#/properties/aiProvider/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
if(!(((((data12 === "openai") || (data12 === "cerebras")) || (data12 === "codex")) || (data12 === "claude")) || (data12 === "anthropic"))){
validate36.errors = [{instancePath:instancePath+"/aiProvider",schemaPath:"#/properties/aiProvider/enum",keyword:"enum",params:{allowedValues: schema49.properties.aiProvider.enum},message:"must be equal to one of the allowed values"}];
return false;
}
var valid16 = _errs39 === errors;
}
else {
var valid16 = true;
}
if(valid16){
if(data.aiModel !== undefined){
let data13 = data.aiModel;
const _errs41 = errors;
if(errors === _errs41){
if(typeof data13 === "string"){
if(func1(data13) > 128){
validate36.errors = [{instancePath:instancePath+"/aiModel",schemaPath:"#/properties/aiModel/maxLength",keyword:"maxLength",params:{limit: 128},message:"must NOT have more than 128 characters"}];
return false;
}
else {
if(func1(data13) < 1){
validate36.errors = [{instancePath:instancePath+"/aiModel",schemaPath:"#/properties/aiModel/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
else {
if(!pattern5.test(data13)){
validate36.errors = [{instancePath:instancePath+"/aiModel",schemaPath:"#/properties/aiModel/pattern",keyword:"pattern",params:{pattern: "^[A-Za-z0-9][A-Za-z0-9._-]*$"},message:"must match pattern \""+"^[A-Za-z0-9][A-Za-z0-9._-]*$"+"\""}];
return false;
}
}
}
}
else {
validate36.errors = [{instancePath:instancePath+"/aiModel",schemaPath:"#/properties/aiModel/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid16 = _errs41 === errors;
}
else {
var valid16 = true;
}
if(valid16){
if(data.aiFastMode !== undefined){
const _errs43 = errors;
if(typeof data.aiFastMode !== "boolean"){
validate36.errors = [{instancePath:instancePath+"/aiFastMode",schemaPath:"#/properties/aiFastMode/type",keyword:"type",params:{type: "boolean"},message:"must be boolean"}];
return false;
}
var valid16 = _errs43 === errors;
}
else {
var valid16 = true;
}
}
}
}
}
}
}
}
else {
validate36.errors = [{instancePath,schemaPath:"#/type",keyword:"type",params:{type: "object"},message:"must be object"}];
return false;
}
}
validate36.errors = vErrors;
return errors === 0;
}
validate36.evaluated = {"props":true,"dynamicProps":false,"dynamicItems":false};

export const lyricsCorrectionStatus = validate37;
const schema50 = {"$schema":"https://json-schema.org/draft/2020-12/schema","$id":"https://transcribed-subtitle-extension.local/schemas/lyrics-correction-status.schema.json","title":"LyricsCorrectionStatus","type":"object","description":"AI selection is pinned per correction. Older backend responses can omit all three AI fields.","dependentRequired":{"aiProvider":["aiModel","aiFastMode"],"aiModel":["aiProvider","aiFastMode"],"aiFastMode":["aiProvider","aiModel"]},"if":{"required":["aiProvider"],"properties":{"aiProvider":{"enum":["openai","cerebras"]}}},"then":{"properties":{"aiFastMode":{"const":false}}},"oneOf":[{"additionalProperties":false,"required":["attemptId","status","stage","updatedAt"],"properties":{"aiProvider":{"enum":["openai","cerebras","codex","claude","anthropic"]},"aiModel":{"type":"string","minLength":1},"aiFastMode":{"type":"boolean"},"attemptId":{"type":"string","format":"uuid"},"status":{"const":"queued"},"stage":{"const":"queued"},"updatedAt":{"type":"string","format":"date-time"}}},{"additionalProperties":false,"required":["attemptId","status","stage","updatedAt"],"properties":{"aiProvider":{"enum":["openai","cerebras","codex","claude","anthropic"]},"aiModel":{"type":"string","minLength":1},"aiFastMode":{"type":"boolean"},"attemptId":{"type":"string","format":"uuid"},"status":{"const":"running"},"stage":{"enum":["aligning","rebuilding","romanizing","finalizing"]},"updatedAt":{"type":"string","format":"date-time"}}},{"additionalProperties":false,"required":["attemptId","status","stage","updatedAt","track"],"properties":{"aiProvider":{"enum":["openai","cerebras","codex","claude","anthropic"]},"aiModel":{"type":"string","minLength":1},"aiFastMode":{"type":"boolean"},"attemptId":{"type":"string","format":"uuid"},"status":{"const":"completed"},"stage":{"const":"completed"},"updatedAt":{"type":"string","format":"date-time"},"track":{"$ref":"track-response.schema.json"}}},{"additionalProperties":false,"required":["attemptId","status","stage","updatedAt","errorCode","message"],"properties":{"aiProvider":{"enum":["openai","cerebras","codex","claude","anthropic"]},"aiModel":{"type":"string","minLength":1},"aiFastMode":{"type":"boolean"},"attemptId":{"type":"string","format":"uuid"},"status":{"const":"failed"},"stage":{"const":"failed"},"updatedAt":{"type":"string","format":"date-time"},"errorCode":{"enum":["lyrics_incomplete","lyrics_do_not_match","lyrics_correction_failed"]},"message":{"type":"string","minLength":1}}},{"additionalProperties":false,"required":["attemptId","status","stage","updatedAt"],"properties":{"aiProvider":{"enum":["openai","cerebras","codex","claude","anthropic"]},"aiModel":{"type":"string","minLength":1},"aiFastMode":{"type":"boolean"},"attemptId":{"type":"string","format":"uuid"},"status":{"const":"cancelled"},"stage":{"const":"cancelled"},"updatedAt":{"type":"string","format":"date-time"}}}]};

function validate37(data, {instancePath="", parentData, parentDataProperty, rootData=data, dynamicAnchors={}}={}){
/*# sourceURL="https://transcribed-subtitle-extension.local/schemas/lyrics-correction-status.schema.json" */;
let vErrors = null;
let errors = 0;
const evaluated0 = validate37.evaluated;
if(evaluated0.dynamicProps){
evaluated0.props = undefined;
}
if(evaluated0.dynamicItems){
evaluated0.items = undefined;
}
const _errs1 = errors;
let valid0 = false;
let passing0 = null;
const _errs2 = errors;
if(data && typeof data == "object" && !Array.isArray(data)){
let missing0;
if(((((data.attemptId === undefined) && (missing0 = "attemptId")) || ((data.status === undefined) && (missing0 = "status"))) || ((data.stage === undefined) && (missing0 = "stage"))) || ((data.updatedAt === undefined) && (missing0 = "updatedAt"))){
const err0 = {instancePath,schemaPath:"#/oneOf/0/required",keyword:"required",params:{missingProperty: missing0},message:"must have required property '"+missing0+"'"};
if(vErrors === null){
vErrors = [err0];
}
else {
vErrors.push(err0);
}
errors++;
}
else {
const _errs3 = errors;
for(const key0 in data){
if(!(((((((key0 === "aiProvider") || (key0 === "aiModel")) || (key0 === "aiFastMode")) || (key0 === "attemptId")) || (key0 === "status")) || (key0 === "stage")) || (key0 === "updatedAt"))){
const err1 = {instancePath,schemaPath:"#/oneOf/0/additionalProperties",keyword:"additionalProperties",params:{additionalProperty: key0},message:"must NOT have additional properties"};
if(vErrors === null){
vErrors = [err1];
}
else {
vErrors.push(err1);
}
errors++;
break;
}
}
if(_errs3 === errors){
if(data.aiProvider !== undefined){
let data0 = data.aiProvider;
const _errs4 = errors;
if(!(((((data0 === "openai") || (data0 === "cerebras")) || (data0 === "codex")) || (data0 === "claude")) || (data0 === "anthropic"))){
const err2 = {instancePath:instancePath+"/aiProvider",schemaPath:"#/oneOf/0/properties/aiProvider/enum",keyword:"enum",params:{allowedValues: schema50.oneOf[0].properties.aiProvider.enum},message:"must be equal to one of the allowed values"};
if(vErrors === null){
vErrors = [err2];
}
else {
vErrors.push(err2);
}
errors++;
}
var valid1 = _errs4 === errors;
}
else {
var valid1 = true;
}
if(valid1){
if(data.aiModel !== undefined){
let data1 = data.aiModel;
const _errs5 = errors;
if(errors === _errs5){
if(typeof data1 === "string"){
if(func1(data1) < 1){
const err3 = {instancePath:instancePath+"/aiModel",schemaPath:"#/oneOf/0/properties/aiModel/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"};
if(vErrors === null){
vErrors = [err3];
}
else {
vErrors.push(err3);
}
errors++;
}
}
else {
const err4 = {instancePath:instancePath+"/aiModel",schemaPath:"#/oneOf/0/properties/aiModel/type",keyword:"type",params:{type: "string"},message:"must be string"};
if(vErrors === null){
vErrors = [err4];
}
else {
vErrors.push(err4);
}
errors++;
}
}
var valid1 = _errs5 === errors;
}
else {
var valid1 = true;
}
if(valid1){
if(data.aiFastMode !== undefined){
const _errs7 = errors;
if(typeof data.aiFastMode !== "boolean"){
const err5 = {instancePath:instancePath+"/aiFastMode",schemaPath:"#/oneOf/0/properties/aiFastMode/type",keyword:"type",params:{type: "boolean"},message:"must be boolean"};
if(vErrors === null){
vErrors = [err5];
}
else {
vErrors.push(err5);
}
errors++;
}
var valid1 = _errs7 === errors;
}
else {
var valid1 = true;
}
if(valid1){
if(data.attemptId !== undefined){
let data3 = data.attemptId;
const _errs9 = errors;
if(errors === _errs9){
if(errors === _errs9){
if(typeof data3 === "string"){
if(!(formats6.test(data3))){
const err6 = {instancePath:instancePath+"/attemptId",schemaPath:"#/oneOf/0/properties/attemptId/format",keyword:"format",params:{format: "uuid"},message:"must match format \""+"uuid"+"\""};
if(vErrors === null){
vErrors = [err6];
}
else {
vErrors.push(err6);
}
errors++;
}
}
else {
const err7 = {instancePath:instancePath+"/attemptId",schemaPath:"#/oneOf/0/properties/attemptId/type",keyword:"type",params:{type: "string"},message:"must be string"};
if(vErrors === null){
vErrors = [err7];
}
else {
vErrors.push(err7);
}
errors++;
}
}
}
var valid1 = _errs9 === errors;
}
else {
var valid1 = true;
}
if(valid1){
if(data.status !== undefined){
const _errs11 = errors;
if("queued" !== data.status){
const err8 = {instancePath:instancePath+"/status",schemaPath:"#/oneOf/0/properties/status/const",keyword:"const",params:{allowedValue: "queued"},message:"must be equal to constant"};
if(vErrors === null){
vErrors = [err8];
}
else {
vErrors.push(err8);
}
errors++;
}
var valid1 = _errs11 === errors;
}
else {
var valid1 = true;
}
if(valid1){
if(data.stage !== undefined){
const _errs12 = errors;
if("queued" !== data.stage){
const err9 = {instancePath:instancePath+"/stage",schemaPath:"#/oneOf/0/properties/stage/const",keyword:"const",params:{allowedValue: "queued"},message:"must be equal to constant"};
if(vErrors === null){
vErrors = [err9];
}
else {
vErrors.push(err9);
}
errors++;
}
var valid1 = _errs12 === errors;
}
else {
var valid1 = true;
}
if(valid1){
if(data.updatedAt !== undefined){
let data6 = data.updatedAt;
const _errs13 = errors;
if(errors === _errs13){
if(errors === _errs13){
if(typeof data6 === "string"){
if(!(formats12.validate.test(data6))){
const err10 = {instancePath:instancePath+"/updatedAt",schemaPath:"#/oneOf/0/properties/updatedAt/format",keyword:"format",params:{format: "date-time"},message:"must match format \""+"date-time"+"\""};
if(vErrors === null){
vErrors = [err10];
}
else {
vErrors.push(err10);
}
errors++;
}
}
else {
const err11 = {instancePath:instancePath+"/updatedAt",schemaPath:"#/oneOf/0/properties/updatedAt/type",keyword:"type",params:{type: "string"},message:"must be string"};
if(vErrors === null){
vErrors = [err11];
}
else {
vErrors.push(err11);
}
errors++;
}
}
}
var valid1 = _errs13 === errors;
}
else {
var valid1 = true;
}
}
}
}
}
}
}
}
}
}
var _valid0 = _errs2 === errors;
if(_valid0){
valid0 = true;
passing0 = 0;
var props0 = true;
}
const _errs15 = errors;
if(data && typeof data == "object" && !Array.isArray(data)){
let missing1;
if(((((data.attemptId === undefined) && (missing1 = "attemptId")) || ((data.status === undefined) && (missing1 = "status"))) || ((data.stage === undefined) && (missing1 = "stage"))) || ((data.updatedAt === undefined) && (missing1 = "updatedAt"))){
const err12 = {instancePath,schemaPath:"#/oneOf/1/required",keyword:"required",params:{missingProperty: missing1},message:"must have required property '"+missing1+"'"};
if(vErrors === null){
vErrors = [err12];
}
else {
vErrors.push(err12);
}
errors++;
}
else {
const _errs16 = errors;
for(const key1 in data){
if(!(((((((key1 === "aiProvider") || (key1 === "aiModel")) || (key1 === "aiFastMode")) || (key1 === "attemptId")) || (key1 === "status")) || (key1 === "stage")) || (key1 === "updatedAt"))){
const err13 = {instancePath,schemaPath:"#/oneOf/1/additionalProperties",keyword:"additionalProperties",params:{additionalProperty: key1},message:"must NOT have additional properties"};
if(vErrors === null){
vErrors = [err13];
}
else {
vErrors.push(err13);
}
errors++;
break;
}
}
if(_errs16 === errors){
if(data.aiProvider !== undefined){
let data7 = data.aiProvider;
const _errs17 = errors;
if(!(((((data7 === "openai") || (data7 === "cerebras")) || (data7 === "codex")) || (data7 === "claude")) || (data7 === "anthropic"))){
const err14 = {instancePath:instancePath+"/aiProvider",schemaPath:"#/oneOf/1/properties/aiProvider/enum",keyword:"enum",params:{allowedValues: schema50.oneOf[1].properties.aiProvider.enum},message:"must be equal to one of the allowed values"};
if(vErrors === null){
vErrors = [err14];
}
else {
vErrors.push(err14);
}
errors++;
}
var valid2 = _errs17 === errors;
}
else {
var valid2 = true;
}
if(valid2){
if(data.aiModel !== undefined){
let data8 = data.aiModel;
const _errs18 = errors;
if(errors === _errs18){
if(typeof data8 === "string"){
if(func1(data8) < 1){
const err15 = {instancePath:instancePath+"/aiModel",schemaPath:"#/oneOf/1/properties/aiModel/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"};
if(vErrors === null){
vErrors = [err15];
}
else {
vErrors.push(err15);
}
errors++;
}
}
else {
const err16 = {instancePath:instancePath+"/aiModel",schemaPath:"#/oneOf/1/properties/aiModel/type",keyword:"type",params:{type: "string"},message:"must be string"};
if(vErrors === null){
vErrors = [err16];
}
else {
vErrors.push(err16);
}
errors++;
}
}
var valid2 = _errs18 === errors;
}
else {
var valid2 = true;
}
if(valid2){
if(data.aiFastMode !== undefined){
const _errs20 = errors;
if(typeof data.aiFastMode !== "boolean"){
const err17 = {instancePath:instancePath+"/aiFastMode",schemaPath:"#/oneOf/1/properties/aiFastMode/type",keyword:"type",params:{type: "boolean"},message:"must be boolean"};
if(vErrors === null){
vErrors = [err17];
}
else {
vErrors.push(err17);
}
errors++;
}
var valid2 = _errs20 === errors;
}
else {
var valid2 = true;
}
if(valid2){
if(data.attemptId !== undefined){
let data10 = data.attemptId;
const _errs22 = errors;
if(errors === _errs22){
if(errors === _errs22){
if(typeof data10 === "string"){
if(!(formats6.test(data10))){
const err18 = {instancePath:instancePath+"/attemptId",schemaPath:"#/oneOf/1/properties/attemptId/format",keyword:"format",params:{format: "uuid"},message:"must match format \""+"uuid"+"\""};
if(vErrors === null){
vErrors = [err18];
}
else {
vErrors.push(err18);
}
errors++;
}
}
else {
const err19 = {instancePath:instancePath+"/attemptId",schemaPath:"#/oneOf/1/properties/attemptId/type",keyword:"type",params:{type: "string"},message:"must be string"};
if(vErrors === null){
vErrors = [err19];
}
else {
vErrors.push(err19);
}
errors++;
}
}
}
var valid2 = _errs22 === errors;
}
else {
var valid2 = true;
}
if(valid2){
if(data.status !== undefined){
const _errs24 = errors;
if("running" !== data.status){
const err20 = {instancePath:instancePath+"/status",schemaPath:"#/oneOf/1/properties/status/const",keyword:"const",params:{allowedValue: "running"},message:"must be equal to constant"};
if(vErrors === null){
vErrors = [err20];
}
else {
vErrors.push(err20);
}
errors++;
}
var valid2 = _errs24 === errors;
}
else {
var valid2 = true;
}
if(valid2){
if(data.stage !== undefined){
let data12 = data.stage;
const _errs25 = errors;
if(!((((data12 === "aligning") || (data12 === "rebuilding")) || (data12 === "romanizing")) || (data12 === "finalizing"))){
const err21 = {instancePath:instancePath+"/stage",schemaPath:"#/oneOf/1/properties/stage/enum",keyword:"enum",params:{allowedValues: schema50.oneOf[1].properties.stage.enum},message:"must be equal to one of the allowed values"};
if(vErrors === null){
vErrors = [err21];
}
else {
vErrors.push(err21);
}
errors++;
}
var valid2 = _errs25 === errors;
}
else {
var valid2 = true;
}
if(valid2){
if(data.updatedAt !== undefined){
let data13 = data.updatedAt;
const _errs26 = errors;
if(errors === _errs26){
if(errors === _errs26){
if(typeof data13 === "string"){
if(!(formats12.validate.test(data13))){
const err22 = {instancePath:instancePath+"/updatedAt",schemaPath:"#/oneOf/1/properties/updatedAt/format",keyword:"format",params:{format: "date-time"},message:"must match format \""+"date-time"+"\""};
if(vErrors === null){
vErrors = [err22];
}
else {
vErrors.push(err22);
}
errors++;
}
}
else {
const err23 = {instancePath:instancePath+"/updatedAt",schemaPath:"#/oneOf/1/properties/updatedAt/type",keyword:"type",params:{type: "string"},message:"must be string"};
if(vErrors === null){
vErrors = [err23];
}
else {
vErrors.push(err23);
}
errors++;
}
}
}
var valid2 = _errs26 === errors;
}
else {
var valid2 = true;
}
}
}
}
}
}
}
}
}
}
var _valid0 = _errs15 === errors;
if(_valid0 && valid0){
valid0 = false;
passing0 = [passing0, 1];
}
else {
if(_valid0){
valid0 = true;
passing0 = 1;
if(props0 !== true){
props0 = true;
}
}
const _errs28 = errors;
if(data && typeof data == "object" && !Array.isArray(data)){
let missing2;
if((((((data.attemptId === undefined) && (missing2 = "attemptId")) || ((data.status === undefined) && (missing2 = "status"))) || ((data.stage === undefined) && (missing2 = "stage"))) || ((data.updatedAt === undefined) && (missing2 = "updatedAt"))) || ((data.track === undefined) && (missing2 = "track"))){
const err24 = {instancePath,schemaPath:"#/oneOf/2/required",keyword:"required",params:{missingProperty: missing2},message:"must have required property '"+missing2+"'"};
if(vErrors === null){
vErrors = [err24];
}
else {
vErrors.push(err24);
}
errors++;
}
else {
const _errs29 = errors;
for(const key2 in data){
if(!((((((((key2 === "aiProvider") || (key2 === "aiModel")) || (key2 === "aiFastMode")) || (key2 === "attemptId")) || (key2 === "status")) || (key2 === "stage")) || (key2 === "updatedAt")) || (key2 === "track"))){
const err25 = {instancePath,schemaPath:"#/oneOf/2/additionalProperties",keyword:"additionalProperties",params:{additionalProperty: key2},message:"must NOT have additional properties"};
if(vErrors === null){
vErrors = [err25];
}
else {
vErrors.push(err25);
}
errors++;
break;
}
}
if(_errs29 === errors){
if(data.aiProvider !== undefined){
let data14 = data.aiProvider;
const _errs30 = errors;
if(!(((((data14 === "openai") || (data14 === "cerebras")) || (data14 === "codex")) || (data14 === "claude")) || (data14 === "anthropic"))){
const err26 = {instancePath:instancePath+"/aiProvider",schemaPath:"#/oneOf/2/properties/aiProvider/enum",keyword:"enum",params:{allowedValues: schema50.oneOf[2].properties.aiProvider.enum},message:"must be equal to one of the allowed values"};
if(vErrors === null){
vErrors = [err26];
}
else {
vErrors.push(err26);
}
errors++;
}
var valid3 = _errs30 === errors;
}
else {
var valid3 = true;
}
if(valid3){
if(data.aiModel !== undefined){
let data15 = data.aiModel;
const _errs31 = errors;
if(errors === _errs31){
if(typeof data15 === "string"){
if(func1(data15) < 1){
const err27 = {instancePath:instancePath+"/aiModel",schemaPath:"#/oneOf/2/properties/aiModel/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"};
if(vErrors === null){
vErrors = [err27];
}
else {
vErrors.push(err27);
}
errors++;
}
}
else {
const err28 = {instancePath:instancePath+"/aiModel",schemaPath:"#/oneOf/2/properties/aiModel/type",keyword:"type",params:{type: "string"},message:"must be string"};
if(vErrors === null){
vErrors = [err28];
}
else {
vErrors.push(err28);
}
errors++;
}
}
var valid3 = _errs31 === errors;
}
else {
var valid3 = true;
}
if(valid3){
if(data.aiFastMode !== undefined){
const _errs33 = errors;
if(typeof data.aiFastMode !== "boolean"){
const err29 = {instancePath:instancePath+"/aiFastMode",schemaPath:"#/oneOf/2/properties/aiFastMode/type",keyword:"type",params:{type: "boolean"},message:"must be boolean"};
if(vErrors === null){
vErrors = [err29];
}
else {
vErrors.push(err29);
}
errors++;
}
var valid3 = _errs33 === errors;
}
else {
var valid3 = true;
}
if(valid3){
if(data.attemptId !== undefined){
let data17 = data.attemptId;
const _errs35 = errors;
if(errors === _errs35){
if(errors === _errs35){
if(typeof data17 === "string"){
if(!(formats6.test(data17))){
const err30 = {instancePath:instancePath+"/attemptId",schemaPath:"#/oneOf/2/properties/attemptId/format",keyword:"format",params:{format: "uuid"},message:"must match format \""+"uuid"+"\""};
if(vErrors === null){
vErrors = [err30];
}
else {
vErrors.push(err30);
}
errors++;
}
}
else {
const err31 = {instancePath:instancePath+"/attemptId",schemaPath:"#/oneOf/2/properties/attemptId/type",keyword:"type",params:{type: "string"},message:"must be string"};
if(vErrors === null){
vErrors = [err31];
}
else {
vErrors.push(err31);
}
errors++;
}
}
}
var valid3 = _errs35 === errors;
}
else {
var valid3 = true;
}
if(valid3){
if(data.status !== undefined){
const _errs37 = errors;
if("completed" !== data.status){
const err32 = {instancePath:instancePath+"/status",schemaPath:"#/oneOf/2/properties/status/const",keyword:"const",params:{allowedValue: "completed"},message:"must be equal to constant"};
if(vErrors === null){
vErrors = [err32];
}
else {
vErrors.push(err32);
}
errors++;
}
var valid3 = _errs37 === errors;
}
else {
var valid3 = true;
}
if(valid3){
if(data.stage !== undefined){
const _errs38 = errors;
if("completed" !== data.stage){
const err33 = {instancePath:instancePath+"/stage",schemaPath:"#/oneOf/2/properties/stage/const",keyword:"const",params:{allowedValue: "completed"},message:"must be equal to constant"};
if(vErrors === null){
vErrors = [err33];
}
else {
vErrors.push(err33);
}
errors++;
}
var valid3 = _errs38 === errors;
}
else {
var valid3 = true;
}
if(valid3){
if(data.updatedAt !== undefined){
let data20 = data.updatedAt;
const _errs39 = errors;
if(errors === _errs39){
if(errors === _errs39){
if(typeof data20 === "string"){
if(!(formats12.validate.test(data20))){
const err34 = {instancePath:instancePath+"/updatedAt",schemaPath:"#/oneOf/2/properties/updatedAt/format",keyword:"format",params:{format: "date-time"},message:"must match format \""+"date-time"+"\""};
if(vErrors === null){
vErrors = [err34];
}
else {
vErrors.push(err34);
}
errors++;
}
}
else {
const err35 = {instancePath:instancePath+"/updatedAt",schemaPath:"#/oneOf/2/properties/updatedAt/type",keyword:"type",params:{type: "string"},message:"must be string"};
if(vErrors === null){
vErrors = [err35];
}
else {
vErrors.push(err35);
}
errors++;
}
}
}
var valid3 = _errs39 === errors;
}
else {
var valid3 = true;
}
if(valid3){
if(data.track !== undefined){
const _errs41 = errors;
if(!(validate28(data.track, {instancePath:instancePath+"/track",parentData:data,parentDataProperty:"track",rootData,dynamicAnchors}))){
vErrors = vErrors === null ? validate28.errors : vErrors.concat(validate28.errors);
errors = vErrors.length;
}
var valid3 = _errs41 === errors;
}
else {
var valid3 = true;
}
}
}
}
}
}
}
}
}
}
}
var _valid0 = _errs28 === errors;
if(_valid0 && valid0){
valid0 = false;
passing0 = [passing0, 2];
}
else {
if(_valid0){
valid0 = true;
passing0 = 2;
if(props0 !== true){
props0 = true;
}
}
const _errs42 = errors;
if(data && typeof data == "object" && !Array.isArray(data)){
let missing3;
if(((((((data.attemptId === undefined) && (missing3 = "attemptId")) || ((data.status === undefined) && (missing3 = "status"))) || ((data.stage === undefined) && (missing3 = "stage"))) || ((data.updatedAt === undefined) && (missing3 = "updatedAt"))) || ((data.errorCode === undefined) && (missing3 = "errorCode"))) || ((data.message === undefined) && (missing3 = "message"))){
const err36 = {instancePath,schemaPath:"#/oneOf/3/required",keyword:"required",params:{missingProperty: missing3},message:"must have required property '"+missing3+"'"};
if(vErrors === null){
vErrors = [err36];
}
else {
vErrors.push(err36);
}
errors++;
}
else {
const _errs43 = errors;
for(const key3 in data){
if(!(func14.call(schema50.oneOf[3].properties, key3))){
const err37 = {instancePath,schemaPath:"#/oneOf/3/additionalProperties",keyword:"additionalProperties",params:{additionalProperty: key3},message:"must NOT have additional properties"};
if(vErrors === null){
vErrors = [err37];
}
else {
vErrors.push(err37);
}
errors++;
break;
}
}
if(_errs43 === errors){
if(data.aiProvider !== undefined){
let data22 = data.aiProvider;
const _errs44 = errors;
if(!(((((data22 === "openai") || (data22 === "cerebras")) || (data22 === "codex")) || (data22 === "claude")) || (data22 === "anthropic"))){
const err38 = {instancePath:instancePath+"/aiProvider",schemaPath:"#/oneOf/3/properties/aiProvider/enum",keyword:"enum",params:{allowedValues: schema50.oneOf[3].properties.aiProvider.enum},message:"must be equal to one of the allowed values"};
if(vErrors === null){
vErrors = [err38];
}
else {
vErrors.push(err38);
}
errors++;
}
var valid4 = _errs44 === errors;
}
else {
var valid4 = true;
}
if(valid4){
if(data.aiModel !== undefined){
let data23 = data.aiModel;
const _errs45 = errors;
if(errors === _errs45){
if(typeof data23 === "string"){
if(func1(data23) < 1){
const err39 = {instancePath:instancePath+"/aiModel",schemaPath:"#/oneOf/3/properties/aiModel/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"};
if(vErrors === null){
vErrors = [err39];
}
else {
vErrors.push(err39);
}
errors++;
}
}
else {
const err40 = {instancePath:instancePath+"/aiModel",schemaPath:"#/oneOf/3/properties/aiModel/type",keyword:"type",params:{type: "string"},message:"must be string"};
if(vErrors === null){
vErrors = [err40];
}
else {
vErrors.push(err40);
}
errors++;
}
}
var valid4 = _errs45 === errors;
}
else {
var valid4 = true;
}
if(valid4){
if(data.aiFastMode !== undefined){
const _errs47 = errors;
if(typeof data.aiFastMode !== "boolean"){
const err41 = {instancePath:instancePath+"/aiFastMode",schemaPath:"#/oneOf/3/properties/aiFastMode/type",keyword:"type",params:{type: "boolean"},message:"must be boolean"};
if(vErrors === null){
vErrors = [err41];
}
else {
vErrors.push(err41);
}
errors++;
}
var valid4 = _errs47 === errors;
}
else {
var valid4 = true;
}
if(valid4){
if(data.attemptId !== undefined){
let data25 = data.attemptId;
const _errs49 = errors;
if(errors === _errs49){
if(errors === _errs49){
if(typeof data25 === "string"){
if(!(formats6.test(data25))){
const err42 = {instancePath:instancePath+"/attemptId",schemaPath:"#/oneOf/3/properties/attemptId/format",keyword:"format",params:{format: "uuid"},message:"must match format \""+"uuid"+"\""};
if(vErrors === null){
vErrors = [err42];
}
else {
vErrors.push(err42);
}
errors++;
}
}
else {
const err43 = {instancePath:instancePath+"/attemptId",schemaPath:"#/oneOf/3/properties/attemptId/type",keyword:"type",params:{type: "string"},message:"must be string"};
if(vErrors === null){
vErrors = [err43];
}
else {
vErrors.push(err43);
}
errors++;
}
}
}
var valid4 = _errs49 === errors;
}
else {
var valid4 = true;
}
if(valid4){
if(data.status !== undefined){
const _errs51 = errors;
if("failed" !== data.status){
const err44 = {instancePath:instancePath+"/status",schemaPath:"#/oneOf/3/properties/status/const",keyword:"const",params:{allowedValue: "failed"},message:"must be equal to constant"};
if(vErrors === null){
vErrors = [err44];
}
else {
vErrors.push(err44);
}
errors++;
}
var valid4 = _errs51 === errors;
}
else {
var valid4 = true;
}
if(valid4){
if(data.stage !== undefined){
const _errs52 = errors;
if("failed" !== data.stage){
const err45 = {instancePath:instancePath+"/stage",schemaPath:"#/oneOf/3/properties/stage/const",keyword:"const",params:{allowedValue: "failed"},message:"must be equal to constant"};
if(vErrors === null){
vErrors = [err45];
}
else {
vErrors.push(err45);
}
errors++;
}
var valid4 = _errs52 === errors;
}
else {
var valid4 = true;
}
if(valid4){
if(data.updatedAt !== undefined){
let data28 = data.updatedAt;
const _errs53 = errors;
if(errors === _errs53){
if(errors === _errs53){
if(typeof data28 === "string"){
if(!(formats12.validate.test(data28))){
const err46 = {instancePath:instancePath+"/updatedAt",schemaPath:"#/oneOf/3/properties/updatedAt/format",keyword:"format",params:{format: "date-time"},message:"must match format \""+"date-time"+"\""};
if(vErrors === null){
vErrors = [err46];
}
else {
vErrors.push(err46);
}
errors++;
}
}
else {
const err47 = {instancePath:instancePath+"/updatedAt",schemaPath:"#/oneOf/3/properties/updatedAt/type",keyword:"type",params:{type: "string"},message:"must be string"};
if(vErrors === null){
vErrors = [err47];
}
else {
vErrors.push(err47);
}
errors++;
}
}
}
var valid4 = _errs53 === errors;
}
else {
var valid4 = true;
}
if(valid4){
if(data.errorCode !== undefined){
let data29 = data.errorCode;
const _errs55 = errors;
if(!(((data29 === "lyrics_incomplete") || (data29 === "lyrics_do_not_match")) || (data29 === "lyrics_correction_failed"))){
const err48 = {instancePath:instancePath+"/errorCode",schemaPath:"#/oneOf/3/properties/errorCode/enum",keyword:"enum",params:{allowedValues: schema50.oneOf[3].properties.errorCode.enum},message:"must be equal to one of the allowed values"};
if(vErrors === null){
vErrors = [err48];
}
else {
vErrors.push(err48);
}
errors++;
}
var valid4 = _errs55 === errors;
}
else {
var valid4 = true;
}
if(valid4){
if(data.message !== undefined){
let data30 = data.message;
const _errs56 = errors;
if(errors === _errs56){
if(typeof data30 === "string"){
if(func1(data30) < 1){
const err49 = {instancePath:instancePath+"/message",schemaPath:"#/oneOf/3/properties/message/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"};
if(vErrors === null){
vErrors = [err49];
}
else {
vErrors.push(err49);
}
errors++;
}
}
else {
const err50 = {instancePath:instancePath+"/message",schemaPath:"#/oneOf/3/properties/message/type",keyword:"type",params:{type: "string"},message:"must be string"};
if(vErrors === null){
vErrors = [err50];
}
else {
vErrors.push(err50);
}
errors++;
}
}
var valid4 = _errs56 === errors;
}
else {
var valid4 = true;
}
}
}
}
}
}
}
}
}
}
}
}
var _valid0 = _errs42 === errors;
if(_valid0 && valid0){
valid0 = false;
passing0 = [passing0, 3];
}
else {
if(_valid0){
valid0 = true;
passing0 = 3;
if(props0 !== true){
props0 = true;
}
}
const _errs58 = errors;
if(data && typeof data == "object" && !Array.isArray(data)){
let missing4;
if(((((data.attemptId === undefined) && (missing4 = "attemptId")) || ((data.status === undefined) && (missing4 = "status"))) || ((data.stage === undefined) && (missing4 = "stage"))) || ((data.updatedAt === undefined) && (missing4 = "updatedAt"))){
const err51 = {instancePath,schemaPath:"#/oneOf/4/required",keyword:"required",params:{missingProperty: missing4},message:"must have required property '"+missing4+"'"};
if(vErrors === null){
vErrors = [err51];
}
else {
vErrors.push(err51);
}
errors++;
}
else {
const _errs59 = errors;
for(const key4 in data){
if(!(((((((key4 === "aiProvider") || (key4 === "aiModel")) || (key4 === "aiFastMode")) || (key4 === "attemptId")) || (key4 === "status")) || (key4 === "stage")) || (key4 === "updatedAt"))){
const err52 = {instancePath,schemaPath:"#/oneOf/4/additionalProperties",keyword:"additionalProperties",params:{additionalProperty: key4},message:"must NOT have additional properties"};
if(vErrors === null){
vErrors = [err52];
}
else {
vErrors.push(err52);
}
errors++;
break;
}
}
if(_errs59 === errors){
if(data.aiProvider !== undefined){
let data31 = data.aiProvider;
const _errs60 = errors;
if(!(((((data31 === "openai") || (data31 === "cerebras")) || (data31 === "codex")) || (data31 === "claude")) || (data31 === "anthropic"))){
const err53 = {instancePath:instancePath+"/aiProvider",schemaPath:"#/oneOf/4/properties/aiProvider/enum",keyword:"enum",params:{allowedValues: schema50.oneOf[4].properties.aiProvider.enum},message:"must be equal to one of the allowed values"};
if(vErrors === null){
vErrors = [err53];
}
else {
vErrors.push(err53);
}
errors++;
}
var valid5 = _errs60 === errors;
}
else {
var valid5 = true;
}
if(valid5){
if(data.aiModel !== undefined){
let data32 = data.aiModel;
const _errs61 = errors;
if(errors === _errs61){
if(typeof data32 === "string"){
if(func1(data32) < 1){
const err54 = {instancePath:instancePath+"/aiModel",schemaPath:"#/oneOf/4/properties/aiModel/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"};
if(vErrors === null){
vErrors = [err54];
}
else {
vErrors.push(err54);
}
errors++;
}
}
else {
const err55 = {instancePath:instancePath+"/aiModel",schemaPath:"#/oneOf/4/properties/aiModel/type",keyword:"type",params:{type: "string"},message:"must be string"};
if(vErrors === null){
vErrors = [err55];
}
else {
vErrors.push(err55);
}
errors++;
}
}
var valid5 = _errs61 === errors;
}
else {
var valid5 = true;
}
if(valid5){
if(data.aiFastMode !== undefined){
const _errs63 = errors;
if(typeof data.aiFastMode !== "boolean"){
const err56 = {instancePath:instancePath+"/aiFastMode",schemaPath:"#/oneOf/4/properties/aiFastMode/type",keyword:"type",params:{type: "boolean"},message:"must be boolean"};
if(vErrors === null){
vErrors = [err56];
}
else {
vErrors.push(err56);
}
errors++;
}
var valid5 = _errs63 === errors;
}
else {
var valid5 = true;
}
if(valid5){
if(data.attemptId !== undefined){
let data34 = data.attemptId;
const _errs65 = errors;
if(errors === _errs65){
if(errors === _errs65){
if(typeof data34 === "string"){
if(!(formats6.test(data34))){
const err57 = {instancePath:instancePath+"/attemptId",schemaPath:"#/oneOf/4/properties/attemptId/format",keyword:"format",params:{format: "uuid"},message:"must match format \""+"uuid"+"\""};
if(vErrors === null){
vErrors = [err57];
}
else {
vErrors.push(err57);
}
errors++;
}
}
else {
const err58 = {instancePath:instancePath+"/attemptId",schemaPath:"#/oneOf/4/properties/attemptId/type",keyword:"type",params:{type: "string"},message:"must be string"};
if(vErrors === null){
vErrors = [err58];
}
else {
vErrors.push(err58);
}
errors++;
}
}
}
var valid5 = _errs65 === errors;
}
else {
var valid5 = true;
}
if(valid5){
if(data.status !== undefined){
const _errs67 = errors;
if("cancelled" !== data.status){
const err59 = {instancePath:instancePath+"/status",schemaPath:"#/oneOf/4/properties/status/const",keyword:"const",params:{allowedValue: "cancelled"},message:"must be equal to constant"};
if(vErrors === null){
vErrors = [err59];
}
else {
vErrors.push(err59);
}
errors++;
}
var valid5 = _errs67 === errors;
}
else {
var valid5 = true;
}
if(valid5){
if(data.stage !== undefined){
const _errs68 = errors;
if("cancelled" !== data.stage){
const err60 = {instancePath:instancePath+"/stage",schemaPath:"#/oneOf/4/properties/stage/const",keyword:"const",params:{allowedValue: "cancelled"},message:"must be equal to constant"};
if(vErrors === null){
vErrors = [err60];
}
else {
vErrors.push(err60);
}
errors++;
}
var valid5 = _errs68 === errors;
}
else {
var valid5 = true;
}
if(valid5){
if(data.updatedAt !== undefined){
let data37 = data.updatedAt;
const _errs69 = errors;
if(errors === _errs69){
if(errors === _errs69){
if(typeof data37 === "string"){
if(!(formats12.validate.test(data37))){
const err61 = {instancePath:instancePath+"/updatedAt",schemaPath:"#/oneOf/4/properties/updatedAt/format",keyword:"format",params:{format: "date-time"},message:"must match format \""+"date-time"+"\""};
if(vErrors === null){
vErrors = [err61];
}
else {
vErrors.push(err61);
}
errors++;
}
}
else {
const err62 = {instancePath:instancePath+"/updatedAt",schemaPath:"#/oneOf/4/properties/updatedAt/type",keyword:"type",params:{type: "string"},message:"must be string"};
if(vErrors === null){
vErrors = [err62];
}
else {
vErrors.push(err62);
}
errors++;
}
}
}
var valid5 = _errs69 === errors;
}
else {
var valid5 = true;
}
}
}
}
}
}
}
}
}
}
var _valid0 = _errs58 === errors;
if(_valid0 && valid0){
valid0 = false;
passing0 = [passing0, 4];
}
else {
if(_valid0){
valid0 = true;
passing0 = 4;
if(props0 !== true){
props0 = true;
}
}
}
}
}
}
if(!valid0){
const err63 = {instancePath,schemaPath:"#/oneOf",keyword:"oneOf",params:{passingSchemas: passing0},message:"must match exactly one schema in oneOf"};
if(vErrors === null){
vErrors = [err63];
}
else {
vErrors.push(err63);
}
errors++;
validate37.errors = vErrors;
return false;
}
else {
errors = _errs1;
if(vErrors !== null){
if(_errs1){
vErrors.length = _errs1;
}
else {
vErrors = null;
}
}
const _errs71 = errors;
let valid6 = true;
const _errs72 = errors;
if(data && typeof data == "object" && !Array.isArray(data)){
let missing5;
if((data.aiProvider === undefined) && (missing5 = "aiProvider")){
const err64 = {};
if(vErrors === null){
vErrors = [err64];
}
else {
vErrors.push(err64);
}
errors++;
}
else {
if(data.aiProvider !== undefined){
let data38 = data.aiProvider;
if(!((data38 === "openai") || (data38 === "cerebras"))){
const err65 = {};
if(vErrors === null){
vErrors = [err65];
}
else {
vErrors.push(err65);
}
errors++;
}
}
}
}
var _valid1 = _errs72 === errors;
if(props0 !== true){
props0 = props0 || {};
props0.aiProvider = true;
}
errors = _errs71;
if(vErrors !== null){
if(_errs71){
vErrors.length = _errs71;
}
else {
vErrors = null;
}
}
if(_valid1){
const _errs74 = errors;
if(data && typeof data == "object" && !Array.isArray(data)){
if(data.aiFastMode !== undefined){
if(false !== data.aiFastMode){
validate37.errors = [{instancePath:instancePath+"/aiFastMode",schemaPath:"#/then/properties/aiFastMode/const",keyword:"const",params:{allowedValue: false},message:"must be equal to constant"}];
return false;
}
}
}
var _valid1 = _errs74 === errors;
valid6 = _valid1;
if(valid6){
if(props0 !== true){
props0 = props0 || {};
props0.aiFastMode = true;
}
}
}
if(!valid6){
const err66 = {instancePath,schemaPath:"#/if",keyword:"if",params:{failingKeyword: "then"},message:"must match \"then\" schema"};
if(vErrors === null){
vErrors = [err66];
}
else {
vErrors.push(err66);
}
errors++;
validate37.errors = vErrors;
return false;
}
}
if(errors === 0){
if(data && typeof data == "object" && !Array.isArray(data)){
let missing6;
if(data.aiProvider !== undefined && (((data.aiModel === undefined) && (missing6 = "aiModel")) || ((data.aiFastMode === undefined) && (missing6 = "aiFastMode")))){
validate37.errors = [{instancePath,schemaPath:"#/dependentRequired",keyword:"dependentRequired",params:{property: "aiProvider",
    missingProperty: missing6,
    depsCount: 2,
    deps: "aiModel, aiFastMode"},message:"must have properties aiModel, aiFastMode when property aiProvider is present"}];
return false;
}
else {
if(data.aiModel !== undefined && (((data.aiProvider === undefined) && (missing6 = "aiProvider")) || ((data.aiFastMode === undefined) && (missing6 = "aiFastMode")))){
validate37.errors = [{instancePath,schemaPath:"#/dependentRequired",keyword:"dependentRequired",params:{property: "aiModel",
    missingProperty: missing6,
    depsCount: 2,
    deps: "aiProvider, aiFastMode"},message:"must have properties aiProvider, aiFastMode when property aiModel is present"}];
return false;
}
else {
if(data.aiFastMode !== undefined && (((data.aiProvider === undefined) && (missing6 = "aiProvider")) || ((data.aiModel === undefined) && (missing6 = "aiModel")))){
validate37.errors = [{instancePath,schemaPath:"#/dependentRequired",keyword:"dependentRequired",params:{property: "aiFastMode",
    missingProperty: missing6,
    depsCount: 2,
    deps: "aiProvider, aiModel"},message:"must have properties aiProvider, aiModel when property aiFastMode is present"}];
return false;
}
}
}
}
else {
validate37.errors = [{instancePath,schemaPath:"#/type",keyword:"type",params:{type: "object"},message:"must be object"}];
return false;
}
}
validate37.errors = vErrors;
evaluated0.props = props0;
return errors === 0;
}
validate37.evaluated = {"dynamicProps":true,"dynamicItems":false};

export const partialTrackResponse = validate31;

export const quickFixTokenRequest = validate39;
const schema51 = {"$schema":"https://json-schema.org/draft/2020-12/schema","$id":"https://transcribed-subtitle-extension.local/schemas/quick-fix-token-request.schema.json","title":"QuickFixTokenRequest","type":"object","additionalProperties":false,"required":["expectedTrackId","text"],"properties":{"expectedTrackId":{"type":"string","format":"uuid"},"text":{"type":"string","minLength":1,"maxLength":84,"pattern":"[^\\s]"}}};
const pattern13 = new RegExp("[^\\s]", "u");

function validate39(data, {instancePath="", parentData, parentDataProperty, rootData=data, dynamicAnchors={}}={}){
/*# sourceURL="https://transcribed-subtitle-extension.local/schemas/quick-fix-token-request.schema.json" */;
let vErrors = null;
let errors = 0;
const evaluated0 = validate39.evaluated;
if(evaluated0.dynamicProps){
evaluated0.props = undefined;
}
if(evaluated0.dynamicItems){
evaluated0.items = undefined;
}
if(errors === 0){
if(data && typeof data == "object" && !Array.isArray(data)){
let missing0;
if(((data.expectedTrackId === undefined) && (missing0 = "expectedTrackId")) || ((data.text === undefined) && (missing0 = "text"))){
validate39.errors = [{instancePath,schemaPath:"#/required",keyword:"required",params:{missingProperty: missing0},message:"must have required property '"+missing0+"'"}];
return false;
}
else {
const _errs1 = errors;
for(const key0 in data){
if(!((key0 === "expectedTrackId") || (key0 === "text"))){
validate39.errors = [{instancePath,schemaPath:"#/additionalProperties",keyword:"additionalProperties",params:{additionalProperty: key0},message:"must NOT have additional properties"}];
return false;
break;
}
}
if(_errs1 === errors){
if(data.expectedTrackId !== undefined){
let data0 = data.expectedTrackId;
const _errs2 = errors;
if(errors === _errs2){
if(errors === _errs2){
if(typeof data0 === "string"){
if(!(formats6.test(data0))){
validate39.errors = [{instancePath:instancePath+"/expectedTrackId",schemaPath:"#/properties/expectedTrackId/format",keyword:"format",params:{format: "uuid"},message:"must match format \""+"uuid"+"\""}];
return false;
}
}
else {
validate39.errors = [{instancePath:instancePath+"/expectedTrackId",schemaPath:"#/properties/expectedTrackId/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
}
var valid0 = _errs2 === errors;
}
else {
var valid0 = true;
}
if(valid0){
if(data.text !== undefined){
let data1 = data.text;
const _errs4 = errors;
if(errors === _errs4){
if(typeof data1 === "string"){
if(func1(data1) > 84){
validate39.errors = [{instancePath:instancePath+"/text",schemaPath:"#/properties/text/maxLength",keyword:"maxLength",params:{limit: 84},message:"must NOT have more than 84 characters"}];
return false;
}
else {
if(func1(data1) < 1){
validate39.errors = [{instancePath:instancePath+"/text",schemaPath:"#/properties/text/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
else {
if(!pattern13.test(data1)){
validate39.errors = [{instancePath:instancePath+"/text",schemaPath:"#/properties/text/pattern",keyword:"pattern",params:{pattern: "[^\\s]"},message:"must match pattern \""+"[^\\s]"+"\""}];
return false;
}
}
}
}
else {
validate39.errors = [{instancePath:instancePath+"/text",schemaPath:"#/properties/text/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid0 = _errs4 === errors;
}
else {
var valid0 = true;
}
}
}
}
}
else {
validate39.errors = [{instancePath,schemaPath:"#/type",keyword:"type",params:{type: "object"},message:"must be object"}];
return false;
}
}
validate39.errors = vErrors;
return errors === 0;
}
validate39.evaluated = {"props":true,"dynamicProps":false,"dynamicItems":false};

export const subtitleJobHistoryResponse = validate40;
const schema52 = {"$schema":"https://json-schema.org/draft/2020-12/schema","$id":"https://transcribed-subtitle-extension.local/schemas/subtitle-job-history-response.schema.json","title":"SubtitleJobHistoryResponse","type":"object","additionalProperties":false,"required":["jobs"],"properties":{"jobs":{"type":"array","items":{"$ref":"#/$defs/SubtitleJobHistoryItem"}}},"$defs":{"SubtitleJobHistoryItem":{"type":"object","additionalProperties":false,"required":["youtubeVideoId","youtubeUrl","status","startedAt","lastUpdatedAt","stage","progressPercent","sourceLanguage","targetLanguage","includeRomanization","includeTranslation","jobId","aiProvider","aiModel"],"properties":{"youtubeVideoId":{"type":"string","pattern":"^[A-Za-z0-9_-]{11}$"},"videoDurationSeconds":{"type":"integer","minimum":1,"description":"Video duration in seconds."},"youtubeUrl":{"type":"string","minLength":1},"status":{"type":"string","enum":["queued","running","completed","failed","cancelled"]},"startedAt":{"type":"string","format":"date-time"},"lastUpdatedAt":{"type":"string","format":"date-time"},"completedAt":{"type":"string","format":"date-time"},"stage":{"type":"string","enum":["preparing","acquiring-audio","optimizing-audio","transcribing","tokenizing","romanizing","translating","finalizing"]},"progressPercent":{"type":"integer","minimum":0,"maximum":100},"sourceLanguage":{"type":"string","enum":["auto","bel","bos","bul","cat","hrv","ces","dan","nld","eng","est","fin","fra","glg","deu","ell","hun","isl","ind","ita","jpn","kan","lav","mkd","msa","mal","nor","pol","por","ron","rus","slk","spa","swe","tur","ukr","vie","hye","aze","ben","yue","fil","kat","guj","hin","kaz","lit","mlt","cmn","mar","nep","ori","fas","srp","slv","swa","tam","tel","afr","ara","asm","ast","mya","hau","heb","jav","kor","kir","ltz","mri","oci","pan","tgk","tha","uzb","cym","amh","lug","ibo","gle","khm","kur","lao","mon","nso","pus","sna","snd","som","urd","wol","xho","yor","zul"]},"includeRomanization":{"type":"boolean","description":"Whether the request asked the backend to add romanization where available."},"includeTranslation":{"type":"boolean","description":"Whether the request asked the backend to translate cue text."},"jobId":{"type":"string","format":"uuid"},"trackId":{"type":"string","format":"uuid"},"expiresAt":{"type":["string","null"],"format":"date-time"},"message":{"type":"string","minLength":1},"errorCode":{"$ref":"error-object.schema.json#/properties/code"},"targetLanguage":{"type":"string","enum":["bel","bos","bul","cat","hrv","ces","dan","nld","eng","est","fin","fra","glg","deu","ell","hun","isl","ind","ita","jpn","kan","lav","mkd","msa","mal","nor","pol","por","ron","rus","slk","spa","swe","tur","ukr","vie","hye","aze","ben","yue","fil","kat","guj","hin","kaz","lit","mlt","cmn","mar","nep","ori","fas","srp","slv","swa","tam","tel","afr","ara","asm","ast","mya","hau","heb","jav","kor","kir","ltz","mri","oci","pan","tgk","tha","uzb","cym","amh","lug","ibo","gle","khm","kur","lao","mon","nso","pus","sna","snd","som","urd","wol","xho","yor","zul"]},"detectedSourceLanguage":{"type":"string","enum":["bel","bos","bul","cat","hrv","ces","dan","nld","eng","est","fin","fra","glg","deu","ell","hun","isl","ind","ita","jpn","kan","lav","mkd","msa","mal","nor","pol","por","ron","rus","slk","spa","swe","tur","ukr","vie","hye","aze","ben","yue","fil","kat","guj","hin","kaz","lit","mlt","cmn","mar","nep","ori","fas","srp","slv","swa","tam","tel","afr","ara","asm","ast","mya","hau","heb","jav","kor","kir","ltz","mri","oci","pan","tgk","tha","uzb","cym","amh","lug","ibo","gle","khm","kur","lao","mon","nso","pus","sna","snd","som","urd","wol","xho","yor","zul"],"description":"Provider-detected source language when sourceLanguage was auto and detection produced a catalog language."},"aiProvider":{"type":"string","enum":["openai","cerebras","codex","claude","anthropic"],"description":"The selected analysis provider stays pinned for this generation."},"aiModel":{"type":"string","minLength":1,"maxLength":128,"description":"Exact text model saved on this job."},"aiFastMode":{"type":"boolean","default":false,"description":"Pinned Codex fast mode. Absent on older jobs means false."}},"allOf":[{"if":{"properties":{"status":{"const":"failed"}}},"then":{"properties":{"errorCode":{},"message":{},"completedAt":{}},"required":["errorCode","message","completedAt"]}},{"if":{"properties":{"status":{"const":"completed"}}},"then":{"properties":{"completedAt":{},"trackId":{},"expiresAt":{}},"required":["completedAt","trackId","expiresAt"]}},{"if":{"properties":{"status":{"const":"cancelled"}}},"then":{"properties":{"completedAt":{},"errorCode":{},"message":{}},"required":["completedAt","errorCode","message"]}}]}}};
const schema53 = {"type":"object","additionalProperties":false,"required":["youtubeVideoId","youtubeUrl","status","startedAt","lastUpdatedAt","stage","progressPercent","sourceLanguage","targetLanguage","includeRomanization","includeTranslation","jobId","aiProvider","aiModel"],"properties":{"youtubeVideoId":{"type":"string","pattern":"^[A-Za-z0-9_-]{11}$"},"videoDurationSeconds":{"type":"integer","minimum":1,"description":"Video duration in seconds."},"youtubeUrl":{"type":"string","minLength":1},"status":{"type":"string","enum":["queued","running","completed","failed","cancelled"]},"startedAt":{"type":"string","format":"date-time"},"lastUpdatedAt":{"type":"string","format":"date-time"},"completedAt":{"type":"string","format":"date-time"},"stage":{"type":"string","enum":["preparing","acquiring-audio","optimizing-audio","transcribing","tokenizing","romanizing","translating","finalizing"]},"progressPercent":{"type":"integer","minimum":0,"maximum":100},"sourceLanguage":{"type":"string","enum":["auto","bel","bos","bul","cat","hrv","ces","dan","nld","eng","est","fin","fra","glg","deu","ell","hun","isl","ind","ita","jpn","kan","lav","mkd","msa","mal","nor","pol","por","ron","rus","slk","spa","swe","tur","ukr","vie","hye","aze","ben","yue","fil","kat","guj","hin","kaz","lit","mlt","cmn","mar","nep","ori","fas","srp","slv","swa","tam","tel","afr","ara","asm","ast","mya","hau","heb","jav","kor","kir","ltz","mri","oci","pan","tgk","tha","uzb","cym","amh","lug","ibo","gle","khm","kur","lao","mon","nso","pus","sna","snd","som","urd","wol","xho","yor","zul"]},"includeRomanization":{"type":"boolean","description":"Whether the request asked the backend to add romanization where available."},"includeTranslation":{"type":"boolean","description":"Whether the request asked the backend to translate cue text."},"jobId":{"type":"string","format":"uuid"},"trackId":{"type":"string","format":"uuid"},"expiresAt":{"type":["string","null"],"format":"date-time"},"message":{"type":"string","minLength":1},"errorCode":{"$ref":"error-object.schema.json#/properties/code"},"targetLanguage":{"type":"string","enum":["bel","bos","bul","cat","hrv","ces","dan","nld","eng","est","fin","fra","glg","deu","ell","hun","isl","ind","ita","jpn","kan","lav","mkd","msa","mal","nor","pol","por","ron","rus","slk","spa","swe","tur","ukr","vie","hye","aze","ben","yue","fil","kat","guj","hin","kaz","lit","mlt","cmn","mar","nep","ori","fas","srp","slv","swa","tam","tel","afr","ara","asm","ast","mya","hau","heb","jav","kor","kir","ltz","mri","oci","pan","tgk","tha","uzb","cym","amh","lug","ibo","gle","khm","kur","lao","mon","nso","pus","sna","snd","som","urd","wol","xho","yor","zul"]},"detectedSourceLanguage":{"type":"string","enum":["bel","bos","bul","cat","hrv","ces","dan","nld","eng","est","fin","fra","glg","deu","ell","hun","isl","ind","ita","jpn","kan","lav","mkd","msa","mal","nor","pol","por","ron","rus","slk","spa","swe","tur","ukr","vie","hye","aze","ben","yue","fil","kat","guj","hin","kaz","lit","mlt","cmn","mar","nep","ori","fas","srp","slv","swa","tam","tel","afr","ara","asm","ast","mya","hau","heb","jav","kor","kir","ltz","mri","oci","pan","tgk","tha","uzb","cym","amh","lug","ibo","gle","khm","kur","lao","mon","nso","pus","sna","snd","som","urd","wol","xho","yor","zul"],"description":"Provider-detected source language when sourceLanguage was auto and detection produced a catalog language."},"aiProvider":{"type":"string","enum":["openai","cerebras","codex","claude","anthropic"],"description":"The selected analysis provider stays pinned for this generation."},"aiModel":{"type":"string","minLength":1,"maxLength":128,"description":"Exact text model saved on this job."},"aiFastMode":{"type":"boolean","default":false,"description":"Pinned Codex fast mode. Absent on older jobs means false."}},"allOf":[{"if":{"properties":{"status":{"const":"failed"}}},"then":{"properties":{"errorCode":{},"message":{},"completedAt":{}},"required":["errorCode","message","completedAt"]}},{"if":{"properties":{"status":{"const":"completed"}}},"then":{"properties":{"completedAt":{},"trackId":{},"expiresAt":{}},"required":["completedAt","trackId","expiresAt"]}},{"if":{"properties":{"status":{"const":"cancelled"}}},"then":{"properties":{"completedAt":{},"errorCode":{},"message":{}},"required":["completedAt","errorCode","message"]}}]};

function validate41(data, {instancePath="", parentData, parentDataProperty, rootData=data, dynamicAnchors={}}={}){
let vErrors = null;
let errors = 0;
const evaluated0 = validate41.evaluated;
if(evaluated0.dynamicProps){
evaluated0.props = undefined;
}
if(evaluated0.dynamicItems){
evaluated0.items = undefined;
}
const _errs1 = errors;
const _errs2 = errors;
let valid1 = true;
const _errs3 = errors;
if(data && typeof data == "object" && !Array.isArray(data)){
if(data.status !== undefined){
if("failed" !== data.status){
const err0 = {};
if(vErrors === null){
vErrors = [err0];
}
else {
vErrors.push(err0);
}
errors++;
}
}
}
var _valid0 = _errs3 === errors;
errors = _errs2;
if(vErrors !== null){
if(_errs2){
vErrors.length = _errs2;
}
else {
vErrors = null;
}
}
if(_valid0){
const _errs5 = errors;
if(data && typeof data == "object" && !Array.isArray(data)){
let missing0;
if((((data.errorCode === undefined) && (missing0 = "errorCode")) || ((data.message === undefined) && (missing0 = "message"))) || ((data.completedAt === undefined) && (missing0 = "completedAt"))){
validate41.errors = [{instancePath,schemaPath:"#/allOf/0/then/required",keyword:"required",params:{missingProperty: missing0},message:"must have required property '"+missing0+"'"}];
return false;
}
}
var _valid0 = _errs5 === errors;
valid1 = _valid0;
if(valid1){
var props0 = {};
props0.errorCode = true;
props0.message = true;
props0.completedAt = true;
props0.status = true;
}
}
if(!valid1){
const err1 = {instancePath,schemaPath:"#/allOf/0/if",keyword:"if",params:{failingKeyword: "then"},message:"must match \"then\" schema"};
if(vErrors === null){
vErrors = [err1];
}
else {
vErrors.push(err1);
}
errors++;
validate41.errors = vErrors;
return false;
}
var valid0 = _errs1 === errors;
if(valid0){
const _errs6 = errors;
const _errs7 = errors;
let valid3 = true;
const _errs8 = errors;
if(data && typeof data == "object" && !Array.isArray(data)){
if(data.status !== undefined){
if("completed" !== data.status){
const err2 = {};
if(vErrors === null){
vErrors = [err2];
}
else {
vErrors.push(err2);
}
errors++;
}
}
}
var _valid1 = _errs8 === errors;
errors = _errs7;
if(vErrors !== null){
if(_errs7){
vErrors.length = _errs7;
}
else {
vErrors = null;
}
}
if(_valid1){
const _errs10 = errors;
if(data && typeof data == "object" && !Array.isArray(data)){
let missing1;
if((((data.completedAt === undefined) && (missing1 = "completedAt")) || ((data.trackId === undefined) && (missing1 = "trackId"))) || ((data.expiresAt === undefined) && (missing1 = "expiresAt"))){
validate41.errors = [{instancePath,schemaPath:"#/allOf/1/then/required",keyword:"required",params:{missingProperty: missing1},message:"must have required property '"+missing1+"'"}];
return false;
}
}
var _valid1 = _errs10 === errors;
valid3 = _valid1;
if(valid3){
var props1 = {};
props1.completedAt = true;
props1.trackId = true;
props1.expiresAt = true;
props1.status = true;
}
}
if(!valid3){
const err3 = {instancePath,schemaPath:"#/allOf/1/if",keyword:"if",params:{failingKeyword: "then"},message:"must match \"then\" schema"};
if(vErrors === null){
vErrors = [err3];
}
else {
vErrors.push(err3);
}
errors++;
validate41.errors = vErrors;
return false;
}
var valid0 = _errs6 === errors;
if(valid0){
if(props0 !== true && props1 !== undefined){
if(props1 === true){
props0 = true;
}
else {
props0 = props0 || {};
Object.assign(props0, props1);
}
}
const _errs11 = errors;
const _errs12 = errors;
let valid5 = true;
const _errs13 = errors;
if(data && typeof data == "object" && !Array.isArray(data)){
if(data.status !== undefined){
if("cancelled" !== data.status){
const err4 = {};
if(vErrors === null){
vErrors = [err4];
}
else {
vErrors.push(err4);
}
errors++;
}
}
}
var _valid2 = _errs13 === errors;
errors = _errs12;
if(vErrors !== null){
if(_errs12){
vErrors.length = _errs12;
}
else {
vErrors = null;
}
}
if(_valid2){
const _errs15 = errors;
if(data && typeof data == "object" && !Array.isArray(data)){
let missing2;
if((((data.completedAt === undefined) && (missing2 = "completedAt")) || ((data.errorCode === undefined) && (missing2 = "errorCode"))) || ((data.message === undefined) && (missing2 = "message"))){
validate41.errors = [{instancePath,schemaPath:"#/allOf/2/then/required",keyword:"required",params:{missingProperty: missing2},message:"must have required property '"+missing2+"'"}];
return false;
}
}
var _valid2 = _errs15 === errors;
valid5 = _valid2;
if(valid5){
var props2 = {};
props2.completedAt = true;
props2.errorCode = true;
props2.message = true;
props2.status = true;
}
}
if(!valid5){
const err5 = {instancePath,schemaPath:"#/allOf/2/if",keyword:"if",params:{failingKeyword: "then"},message:"must match \"then\" schema"};
if(vErrors === null){
vErrors = [err5];
}
else {
vErrors.push(err5);
}
errors++;
validate41.errors = vErrors;
return false;
}
var valid0 = _errs11 === errors;
if(valid0){
if(props0 !== true && props2 !== undefined){
if(props2 === true){
props0 = true;
}
else {
props0 = props0 || {};
Object.assign(props0, props2);
}
}
}
}
}
if(errors === 0){
if(data && typeof data == "object" && !Array.isArray(data)){
let missing3;
if(((((((((((((((data.youtubeVideoId === undefined) && (missing3 = "youtubeVideoId")) || ((data.youtubeUrl === undefined) && (missing3 = "youtubeUrl"))) || ((data.status === undefined) && (missing3 = "status"))) || ((data.startedAt === undefined) && (missing3 = "startedAt"))) || ((data.lastUpdatedAt === undefined) && (missing3 = "lastUpdatedAt"))) || ((data.stage === undefined) && (missing3 = "stage"))) || ((data.progressPercent === undefined) && (missing3 = "progressPercent"))) || ((data.sourceLanguage === undefined) && (missing3 = "sourceLanguage"))) || ((data.targetLanguage === undefined) && (missing3 = "targetLanguage"))) || ((data.includeRomanization === undefined) && (missing3 = "includeRomanization"))) || ((data.includeTranslation === undefined) && (missing3 = "includeTranslation"))) || ((data.jobId === undefined) && (missing3 = "jobId"))) || ((data.aiProvider === undefined) && (missing3 = "aiProvider"))) || ((data.aiModel === undefined) && (missing3 = "aiModel"))){
validate41.errors = [{instancePath,schemaPath:"#/required",keyword:"required",params:{missingProperty: missing3},message:"must have required property '"+missing3+"'"}];
return false;
}
else {
const _errs16 = errors;
for(const key0 in data){
if(!(func14.call(schema53.properties, key0))){
validate41.errors = [{instancePath,schemaPath:"#/additionalProperties",keyword:"additionalProperties",params:{additionalProperty: key0},message:"must NOT have additional properties"}];
return false;
break;
}
}
if(_errs16 === errors){
if(data.youtubeVideoId !== undefined){
let data3 = data.youtubeVideoId;
const _errs17 = errors;
if(errors === _errs17){
if(typeof data3 === "string"){
if(!pattern7.test(data3)){
validate41.errors = [{instancePath:instancePath+"/youtubeVideoId",schemaPath:"#/properties/youtubeVideoId/pattern",keyword:"pattern",params:{pattern: "^[A-Za-z0-9_-]{11}$"},message:"must match pattern \""+"^[A-Za-z0-9_-]{11}$"+"\""}];
return false;
}
}
else {
validate41.errors = [{instancePath:instancePath+"/youtubeVideoId",schemaPath:"#/properties/youtubeVideoId/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid7 = _errs17 === errors;
}
else {
var valid7 = true;
}
if(valid7){
if(data.videoDurationSeconds !== undefined){
let data4 = data.videoDurationSeconds;
const _errs19 = errors;
if(!(((typeof data4 == "number") && (!(data4 % 1) && !isNaN(data4))) && (isFinite(data4)))){
validate41.errors = [{instancePath:instancePath+"/videoDurationSeconds",schemaPath:"#/properties/videoDurationSeconds/type",keyword:"type",params:{type: "integer"},message:"must be integer"}];
return false;
}
if(errors === _errs19){
if((typeof data4 == "number") && (isFinite(data4))){
if(data4 < 1 || isNaN(data4)){
validate41.errors = [{instancePath:instancePath+"/videoDurationSeconds",schemaPath:"#/properties/videoDurationSeconds/minimum",keyword:"minimum",params:{comparison: ">=", limit: 1},message:"must be >= 1"}];
return false;
}
}
}
var valid7 = _errs19 === errors;
}
else {
var valid7 = true;
}
if(valid7){
if(data.youtubeUrl !== undefined){
let data5 = data.youtubeUrl;
const _errs21 = errors;
if(errors === _errs21){
if(typeof data5 === "string"){
if(func1(data5) < 1){
validate41.errors = [{instancePath:instancePath+"/youtubeUrl",schemaPath:"#/properties/youtubeUrl/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
}
else {
validate41.errors = [{instancePath:instancePath+"/youtubeUrl",schemaPath:"#/properties/youtubeUrl/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid7 = _errs21 === errors;
}
else {
var valid7 = true;
}
if(valid7){
if(data.status !== undefined){
let data6 = data.status;
const _errs23 = errors;
if(typeof data6 !== "string"){
validate41.errors = [{instancePath:instancePath+"/status",schemaPath:"#/properties/status/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
if(!(((((data6 === "queued") || (data6 === "running")) || (data6 === "completed")) || (data6 === "failed")) || (data6 === "cancelled"))){
validate41.errors = [{instancePath:instancePath+"/status",schemaPath:"#/properties/status/enum",keyword:"enum",params:{allowedValues: schema53.properties.status.enum},message:"must be equal to one of the allowed values"}];
return false;
}
var valid7 = _errs23 === errors;
}
else {
var valid7 = true;
}
if(valid7){
if(data.startedAt !== undefined){
let data7 = data.startedAt;
const _errs25 = errors;
if(errors === _errs25){
if(errors === _errs25){
if(typeof data7 === "string"){
if(!(formats12.validate.test(data7))){
validate41.errors = [{instancePath:instancePath+"/startedAt",schemaPath:"#/properties/startedAt/format",keyword:"format",params:{format: "date-time"},message:"must match format \""+"date-time"+"\""}];
return false;
}
}
else {
validate41.errors = [{instancePath:instancePath+"/startedAt",schemaPath:"#/properties/startedAt/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
}
var valid7 = _errs25 === errors;
}
else {
var valid7 = true;
}
if(valid7){
if(data.lastUpdatedAt !== undefined){
let data8 = data.lastUpdatedAt;
const _errs27 = errors;
if(errors === _errs27){
if(errors === _errs27){
if(typeof data8 === "string"){
if(!(formats12.validate.test(data8))){
validate41.errors = [{instancePath:instancePath+"/lastUpdatedAt",schemaPath:"#/properties/lastUpdatedAt/format",keyword:"format",params:{format: "date-time"},message:"must match format \""+"date-time"+"\""}];
return false;
}
}
else {
validate41.errors = [{instancePath:instancePath+"/lastUpdatedAt",schemaPath:"#/properties/lastUpdatedAt/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
}
var valid7 = _errs27 === errors;
}
else {
var valid7 = true;
}
if(valid7){
if(data.completedAt !== undefined){
let data9 = data.completedAt;
const _errs29 = errors;
if(errors === _errs29){
if(errors === _errs29){
if(typeof data9 === "string"){
if(!(formats12.validate.test(data9))){
validate41.errors = [{instancePath:instancePath+"/completedAt",schemaPath:"#/properties/completedAt/format",keyword:"format",params:{format: "date-time"},message:"must match format \""+"date-time"+"\""}];
return false;
}
}
else {
validate41.errors = [{instancePath:instancePath+"/completedAt",schemaPath:"#/properties/completedAt/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
}
var valid7 = _errs29 === errors;
}
else {
var valid7 = true;
}
if(valid7){
if(data.stage !== undefined){
let data10 = data.stage;
const _errs31 = errors;
if(typeof data10 !== "string"){
validate41.errors = [{instancePath:instancePath+"/stage",schemaPath:"#/properties/stage/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
if(!((((((((data10 === "preparing") || (data10 === "acquiring-audio")) || (data10 === "optimizing-audio")) || (data10 === "transcribing")) || (data10 === "tokenizing")) || (data10 === "romanizing")) || (data10 === "translating")) || (data10 === "finalizing"))){
validate41.errors = [{instancePath:instancePath+"/stage",schemaPath:"#/properties/stage/enum",keyword:"enum",params:{allowedValues: schema53.properties.stage.enum},message:"must be equal to one of the allowed values"}];
return false;
}
var valid7 = _errs31 === errors;
}
else {
var valid7 = true;
}
if(valid7){
if(data.progressPercent !== undefined){
let data11 = data.progressPercent;
const _errs33 = errors;
if(!(((typeof data11 == "number") && (!(data11 % 1) && !isNaN(data11))) && (isFinite(data11)))){
validate41.errors = [{instancePath:instancePath+"/progressPercent",schemaPath:"#/properties/progressPercent/type",keyword:"type",params:{type: "integer"},message:"must be integer"}];
return false;
}
if(errors === _errs33){
if((typeof data11 == "number") && (isFinite(data11))){
if(data11 > 100 || isNaN(data11)){
validate41.errors = [{instancePath:instancePath+"/progressPercent",schemaPath:"#/properties/progressPercent/maximum",keyword:"maximum",params:{comparison: "<=", limit: 100},message:"must be <= 100"}];
return false;
}
else {
if(data11 < 0 || isNaN(data11)){
validate41.errors = [{instancePath:instancePath+"/progressPercent",schemaPath:"#/properties/progressPercent/minimum",keyword:"minimum",params:{comparison: ">=", limit: 0},message:"must be >= 0"}];
return false;
}
}
}
}
var valid7 = _errs33 === errors;
}
else {
var valid7 = true;
}
if(valid7){
if(data.sourceLanguage !== undefined){
let data12 = data.sourceLanguage;
const _errs35 = errors;
if(typeof data12 !== "string"){
validate41.errors = [{instancePath:instancePath+"/sourceLanguage",schemaPath:"#/properties/sourceLanguage/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
if(!((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((data12 === "auto") || (data12 === "bel")) || (data12 === "bos")) || (data12 === "bul")) || (data12 === "cat")) || (data12 === "hrv")) || (data12 === "ces")) || (data12 === "dan")) || (data12 === "nld")) || (data12 === "eng")) || (data12 === "est")) || (data12 === "fin")) || (data12 === "fra")) || (data12 === "glg")) || (data12 === "deu")) || (data12 === "ell")) || (data12 === "hun")) || (data12 === "isl")) || (data12 === "ind")) || (data12 === "ita")) || (data12 === "jpn")) || (data12 === "kan")) || (data12 === "lav")) || (data12 === "mkd")) || (data12 === "msa")) || (data12 === "mal")) || (data12 === "nor")) || (data12 === "pol")) || (data12 === "por")) || (data12 === "ron")) || (data12 === "rus")) || (data12 === "slk")) || (data12 === "spa")) || (data12 === "swe")) || (data12 === "tur")) || (data12 === "ukr")) || (data12 === "vie")) || (data12 === "hye")) || (data12 === "aze")) || (data12 === "ben")) || (data12 === "yue")) || (data12 === "fil")) || (data12 === "kat")) || (data12 === "guj")) || (data12 === "hin")) || (data12 === "kaz")) || (data12 === "lit")) || (data12 === "mlt")) || (data12 === "cmn")) || (data12 === "mar")) || (data12 === "nep")) || (data12 === "ori")) || (data12 === "fas")) || (data12 === "srp")) || (data12 === "slv")) || (data12 === "swa")) || (data12 === "tam")) || (data12 === "tel")) || (data12 === "afr")) || (data12 === "ara")) || (data12 === "asm")) || (data12 === "ast")) || (data12 === "mya")) || (data12 === "hau")) || (data12 === "heb")) || (data12 === "jav")) || (data12 === "kor")) || (data12 === "kir")) || (data12 === "ltz")) || (data12 === "mri")) || (data12 === "oci")) || (data12 === "pan")) || (data12 === "tgk")) || (data12 === "tha")) || (data12 === "uzb")) || (data12 === "cym")) || (data12 === "amh")) || (data12 === "lug")) || (data12 === "ibo")) || (data12 === "gle")) || (data12 === "khm")) || (data12 === "kur")) || (data12 === "lao")) || (data12 === "mon")) || (data12 === "nso")) || (data12 === "pus")) || (data12 === "sna")) || (data12 === "snd")) || (data12 === "som")) || (data12 === "urd")) || (data12 === "wol")) || (data12 === "xho")) || (data12 === "yor")) || (data12 === "zul"))){
validate41.errors = [{instancePath:instancePath+"/sourceLanguage",schemaPath:"#/properties/sourceLanguage/enum",keyword:"enum",params:{allowedValues: schema53.properties.sourceLanguage.enum},message:"must be equal to one of the allowed values"}];
return false;
}
var valid7 = _errs35 === errors;
}
else {
var valid7 = true;
}
if(valid7){
if(data.includeRomanization !== undefined){
const _errs37 = errors;
if(typeof data.includeRomanization !== "boolean"){
validate41.errors = [{instancePath:instancePath+"/includeRomanization",schemaPath:"#/properties/includeRomanization/type",keyword:"type",params:{type: "boolean"},message:"must be boolean"}];
return false;
}
var valid7 = _errs37 === errors;
}
else {
var valid7 = true;
}
if(valid7){
if(data.includeTranslation !== undefined){
const _errs39 = errors;
if(typeof data.includeTranslation !== "boolean"){
validate41.errors = [{instancePath:instancePath+"/includeTranslation",schemaPath:"#/properties/includeTranslation/type",keyword:"type",params:{type: "boolean"},message:"must be boolean"}];
return false;
}
var valid7 = _errs39 === errors;
}
else {
var valid7 = true;
}
if(valid7){
if(data.jobId !== undefined){
let data15 = data.jobId;
const _errs41 = errors;
if(errors === _errs41){
if(errors === _errs41){
if(typeof data15 === "string"){
if(!(formats6.test(data15))){
validate41.errors = [{instancePath:instancePath+"/jobId",schemaPath:"#/properties/jobId/format",keyword:"format",params:{format: "uuid"},message:"must match format \""+"uuid"+"\""}];
return false;
}
}
else {
validate41.errors = [{instancePath:instancePath+"/jobId",schemaPath:"#/properties/jobId/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
}
var valid7 = _errs41 === errors;
}
else {
var valid7 = true;
}
if(valid7){
if(data.trackId !== undefined){
let data16 = data.trackId;
const _errs43 = errors;
if(errors === _errs43){
if(errors === _errs43){
if(typeof data16 === "string"){
if(!(formats6.test(data16))){
validate41.errors = [{instancePath:instancePath+"/trackId",schemaPath:"#/properties/trackId/format",keyword:"format",params:{format: "uuid"},message:"must match format \""+"uuid"+"\""}];
return false;
}
}
else {
validate41.errors = [{instancePath:instancePath+"/trackId",schemaPath:"#/properties/trackId/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
}
var valid7 = _errs43 === errors;
}
else {
var valid7 = true;
}
if(valid7){
if(data.expiresAt !== undefined){
let data17 = data.expiresAt;
const _errs45 = errors;
if((typeof data17 !== "string") && (data17 !== null)){
validate41.errors = [{instancePath:instancePath+"/expiresAt",schemaPath:"#/properties/expiresAt/type",keyword:"type",params:{type: schema53.properties.expiresAt.type},message:"must be string,null"}];
return false;
}
if(errors === _errs45){
if(errors === _errs45){
if(typeof data17 === "string"){
if(!(formats12.validate.test(data17))){
validate41.errors = [{instancePath:instancePath+"/expiresAt",schemaPath:"#/properties/expiresAt/format",keyword:"format",params:{format: "date-time"},message:"must match format \""+"date-time"+"\""}];
return false;
}
}
}
}
var valid7 = _errs45 === errors;
}
else {
var valid7 = true;
}
if(valid7){
if(data.message !== undefined){
let data18 = data.message;
const _errs47 = errors;
if(errors === _errs47){
if(typeof data18 === "string"){
if(func1(data18) < 1){
validate41.errors = [{instancePath:instancePath+"/message",schemaPath:"#/properties/message/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
}
else {
validate41.errors = [{instancePath:instancePath+"/message",schemaPath:"#/properties/message/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid7 = _errs47 === errors;
}
else {
var valid7 = true;
}
if(valid7){
if(data.errorCode !== undefined){
let data19 = data.errorCode;
const _errs49 = errors;
if(typeof data19 !== "string"){
validate41.errors = [{instancePath:instancePath+"/errorCode",schemaPath:"error-object.schema.json#/properties/code/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
if(!((((((((((((((((((((data19 === "validation_failed") || (data19 === "unauthorized")) || (data19 === "unsupported_video")) || (data19 === "audio_unavailable")) || (data19 === "audio_acquisition_failed")) || (data19 === "queue_publication_failed")) || (data19 === "generation_cancelled")) || (data19 === "generation_not_cancellable")) || (data19 === "transcription_failed")) || (data19 === "enrichment_failed")) || (data19 === "rate_limited")) || (data19 === "not_found")) || (data19 === "expired")) || (data19 === "lyrics_correction_in_progress")) || (data19 === "lyrics_incomplete")) || (data19 === "lyrics_do_not_match")) || (data19 === "lyrics_correction_failed")) || (data19 === "internal_error")) || (data19 === "provider_not_configured")) || (data19 === "provider_unavailable"))){
validate41.errors = [{instancePath:instancePath+"/errorCode",schemaPath:"error-object.schema.json#/properties/code/enum",keyword:"enum",params:{allowedValues: schema42.enum},message:"must be equal to one of the allowed values"}];
return false;
}
var valid7 = _errs49 === errors;
}
else {
var valid7 = true;
}
if(valid7){
if(data.targetLanguage !== undefined){
let data20 = data.targetLanguage;
const _errs52 = errors;
if(typeof data20 !== "string"){
validate41.errors = [{instancePath:instancePath+"/targetLanguage",schemaPath:"#/properties/targetLanguage/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
if(!(((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((data20 === "bel") || (data20 === "bos")) || (data20 === "bul")) || (data20 === "cat")) || (data20 === "hrv")) || (data20 === "ces")) || (data20 === "dan")) || (data20 === "nld")) || (data20 === "eng")) || (data20 === "est")) || (data20 === "fin")) || (data20 === "fra")) || (data20 === "glg")) || (data20 === "deu")) || (data20 === "ell")) || (data20 === "hun")) || (data20 === "isl")) || (data20 === "ind")) || (data20 === "ita")) || (data20 === "jpn")) || (data20 === "kan")) || (data20 === "lav")) || (data20 === "mkd")) || (data20 === "msa")) || (data20 === "mal")) || (data20 === "nor")) || (data20 === "pol")) || (data20 === "por")) || (data20 === "ron")) || (data20 === "rus")) || (data20 === "slk")) || (data20 === "spa")) || (data20 === "swe")) || (data20 === "tur")) || (data20 === "ukr")) || (data20 === "vie")) || (data20 === "hye")) || (data20 === "aze")) || (data20 === "ben")) || (data20 === "yue")) || (data20 === "fil")) || (data20 === "kat")) || (data20 === "guj")) || (data20 === "hin")) || (data20 === "kaz")) || (data20 === "lit")) || (data20 === "mlt")) || (data20 === "cmn")) || (data20 === "mar")) || (data20 === "nep")) || (data20 === "ori")) || (data20 === "fas")) || (data20 === "srp")) || (data20 === "slv")) || (data20 === "swa")) || (data20 === "tam")) || (data20 === "tel")) || (data20 === "afr")) || (data20 === "ara")) || (data20 === "asm")) || (data20 === "ast")) || (data20 === "mya")) || (data20 === "hau")) || (data20 === "heb")) || (data20 === "jav")) || (data20 === "kor")) || (data20 === "kir")) || (data20 === "ltz")) || (data20 === "mri")) || (data20 === "oci")) || (data20 === "pan")) || (data20 === "tgk")) || (data20 === "tha")) || (data20 === "uzb")) || (data20 === "cym")) || (data20 === "amh")) || (data20 === "lug")) || (data20 === "ibo")) || (data20 === "gle")) || (data20 === "khm")) || (data20 === "kur")) || (data20 === "lao")) || (data20 === "mon")) || (data20 === "nso")) || (data20 === "pus")) || (data20 === "sna")) || (data20 === "snd")) || (data20 === "som")) || (data20 === "urd")) || (data20 === "wol")) || (data20 === "xho")) || (data20 === "yor")) || (data20 === "zul"))){
validate41.errors = [{instancePath:instancePath+"/targetLanguage",schemaPath:"#/properties/targetLanguage/enum",keyword:"enum",params:{allowedValues: schema53.properties.targetLanguage.enum},message:"must be equal to one of the allowed values"}];
return false;
}
var valid7 = _errs52 === errors;
}
else {
var valid7 = true;
}
if(valid7){
if(data.detectedSourceLanguage !== undefined){
let data21 = data.detectedSourceLanguage;
const _errs54 = errors;
if(typeof data21 !== "string"){
validate41.errors = [{instancePath:instancePath+"/detectedSourceLanguage",schemaPath:"#/properties/detectedSourceLanguage/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
if(!(((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((data21 === "bel") || (data21 === "bos")) || (data21 === "bul")) || (data21 === "cat")) || (data21 === "hrv")) || (data21 === "ces")) || (data21 === "dan")) || (data21 === "nld")) || (data21 === "eng")) || (data21 === "est")) || (data21 === "fin")) || (data21 === "fra")) || (data21 === "glg")) || (data21 === "deu")) || (data21 === "ell")) || (data21 === "hun")) || (data21 === "isl")) || (data21 === "ind")) || (data21 === "ita")) || (data21 === "jpn")) || (data21 === "kan")) || (data21 === "lav")) || (data21 === "mkd")) || (data21 === "msa")) || (data21 === "mal")) || (data21 === "nor")) || (data21 === "pol")) || (data21 === "por")) || (data21 === "ron")) || (data21 === "rus")) || (data21 === "slk")) || (data21 === "spa")) || (data21 === "swe")) || (data21 === "tur")) || (data21 === "ukr")) || (data21 === "vie")) || (data21 === "hye")) || (data21 === "aze")) || (data21 === "ben")) || (data21 === "yue")) || (data21 === "fil")) || (data21 === "kat")) || (data21 === "guj")) || (data21 === "hin")) || (data21 === "kaz")) || (data21 === "lit")) || (data21 === "mlt")) || (data21 === "cmn")) || (data21 === "mar")) || (data21 === "nep")) || (data21 === "ori")) || (data21 === "fas")) || (data21 === "srp")) || (data21 === "slv")) || (data21 === "swa")) || (data21 === "tam")) || (data21 === "tel")) || (data21 === "afr")) || (data21 === "ara")) || (data21 === "asm")) || (data21 === "ast")) || (data21 === "mya")) || (data21 === "hau")) || (data21 === "heb")) || (data21 === "jav")) || (data21 === "kor")) || (data21 === "kir")) || (data21 === "ltz")) || (data21 === "mri")) || (data21 === "oci")) || (data21 === "pan")) || (data21 === "tgk")) || (data21 === "tha")) || (data21 === "uzb")) || (data21 === "cym")) || (data21 === "amh")) || (data21 === "lug")) || (data21 === "ibo")) || (data21 === "gle")) || (data21 === "khm")) || (data21 === "kur")) || (data21 === "lao")) || (data21 === "mon")) || (data21 === "nso")) || (data21 === "pus")) || (data21 === "sna")) || (data21 === "snd")) || (data21 === "som")) || (data21 === "urd")) || (data21 === "wol")) || (data21 === "xho")) || (data21 === "yor")) || (data21 === "zul"))){
validate41.errors = [{instancePath:instancePath+"/detectedSourceLanguage",schemaPath:"#/properties/detectedSourceLanguage/enum",keyword:"enum",params:{allowedValues: schema53.properties.detectedSourceLanguage.enum},message:"must be equal to one of the allowed values"}];
return false;
}
var valid7 = _errs54 === errors;
}
else {
var valid7 = true;
}
if(valid7){
if(data.aiProvider !== undefined){
let data22 = data.aiProvider;
const _errs56 = errors;
if(typeof data22 !== "string"){
validate41.errors = [{instancePath:instancePath+"/aiProvider",schemaPath:"#/properties/aiProvider/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
if(!(((((data22 === "openai") || (data22 === "cerebras")) || (data22 === "codex")) || (data22 === "claude")) || (data22 === "anthropic"))){
validate41.errors = [{instancePath:instancePath+"/aiProvider",schemaPath:"#/properties/aiProvider/enum",keyword:"enum",params:{allowedValues: schema53.properties.aiProvider.enum},message:"must be equal to one of the allowed values"}];
return false;
}
var valid7 = _errs56 === errors;
}
else {
var valid7 = true;
}
if(valid7){
if(data.aiModel !== undefined){
let data23 = data.aiModel;
const _errs58 = errors;
if(errors === _errs58){
if(typeof data23 === "string"){
if(func1(data23) > 128){
validate41.errors = [{instancePath:instancePath+"/aiModel",schemaPath:"#/properties/aiModel/maxLength",keyword:"maxLength",params:{limit: 128},message:"must NOT have more than 128 characters"}];
return false;
}
else {
if(func1(data23) < 1){
validate41.errors = [{instancePath:instancePath+"/aiModel",schemaPath:"#/properties/aiModel/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
}
}
else {
validate41.errors = [{instancePath:instancePath+"/aiModel",schemaPath:"#/properties/aiModel/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid7 = _errs58 === errors;
}
else {
var valid7 = true;
}
if(valid7){
if(data.aiFastMode !== undefined){
const _errs60 = errors;
if(typeof data.aiFastMode !== "boolean"){
validate41.errors = [{instancePath:instancePath+"/aiFastMode",schemaPath:"#/properties/aiFastMode/type",keyword:"type",params:{type: "boolean"},message:"must be boolean"}];
return false;
}
var valid7 = _errs60 === errors;
}
else {
var valid7 = true;
}
}
}
}
}
}
}
}
}
}
}
}
}
}
}
}
}
}
}
}
}
}
}
}
}
else {
validate41.errors = [{instancePath,schemaPath:"#/type",keyword:"type",params:{type: "object"},message:"must be object"}];
return false;
}
}
validate41.errors = vErrors;
return errors === 0;
}
validate41.evaluated = {"props":true,"dynamicProps":false,"dynamicItems":false};


function validate40(data, {instancePath="", parentData, parentDataProperty, rootData=data, dynamicAnchors={}}={}){
/*# sourceURL="https://transcribed-subtitle-extension.local/schemas/subtitle-job-history-response.schema.json" */;
let vErrors = null;
let errors = 0;
const evaluated0 = validate40.evaluated;
if(evaluated0.dynamicProps){
evaluated0.props = undefined;
}
if(evaluated0.dynamicItems){
evaluated0.items = undefined;
}
if(errors === 0){
if(data && typeof data == "object" && !Array.isArray(data)){
let missing0;
if((data.jobs === undefined) && (missing0 = "jobs")){
validate40.errors = [{instancePath,schemaPath:"#/required",keyword:"required",params:{missingProperty: missing0},message:"must have required property '"+missing0+"'"}];
return false;
}
else {
const _errs1 = errors;
for(const key0 in data){
if(!(key0 === "jobs")){
validate40.errors = [{instancePath,schemaPath:"#/additionalProperties",keyword:"additionalProperties",params:{additionalProperty: key0},message:"must NOT have additional properties"}];
return false;
break;
}
}
if(_errs1 === errors){
if(data.jobs !== undefined){
let data0 = data.jobs;
const _errs2 = errors;
if(errors === _errs2){
if(Array.isArray(data0)){
var valid1 = true;
const len0 = data0.length;
for(let i0=0; i0<len0; i0++){
const _errs4 = errors;
if(!(validate41(data0[i0], {instancePath:instancePath+"/jobs/" + i0,parentData:data0,parentDataProperty:i0,rootData,dynamicAnchors}))){
vErrors = vErrors === null ? validate41.errors : vErrors.concat(validate41.errors);
errors = vErrors.length;
}
var valid1 = _errs4 === errors;
if(!valid1){
break;
}
}
}
else {
validate40.errors = [{instancePath:instancePath+"/jobs",schemaPath:"#/properties/jobs/type",keyword:"type",params:{type: "array"},message:"must be array"}];
return false;
}
}
}
}
}
}
else {
validate40.errors = [{instancePath,schemaPath:"#/type",keyword:"type",params:{type: "object"},message:"must be object"}];
return false;
}
}
validate40.errors = vErrors;
return errors === 0;
}
validate40.evaluated = {"props":true,"dynamicProps":false,"dynamicItems":false};

export const token = validate43;

function validate43(data, {instancePath="", parentData, parentDataProperty, rootData=data, dynamicAnchors={}}={}){
/*# sourceURL="https://transcribed-subtitle-extension.local/schemas/token.schema.json" */;
let vErrors = null;
let errors = 0;
const evaluated0 = validate43.evaluated;
if(evaluated0.dynamicProps){
evaluated0.props = undefined;
}
if(evaluated0.dynamicItems){
evaluated0.items = undefined;
}
if(errors === 0){
if(data && typeof data == "object" && !Array.isArray(data)){
let missing0;
if((((data.index === undefined) && (missing0 = "index")) || ((data.text === undefined) && (missing0 = "text"))) || ((data.normalizedText === undefined) && (missing0 = "normalizedText"))){
validate43.errors = [{instancePath,schemaPath:"#/required",keyword:"required",params:{missingProperty: missing0},message:"must have required property '"+missing0+"'"}];
return false;
}
else {
const _errs1 = errors;
for(const key0 in data){
if(!(func14.call(schema37.properties, key0))){
validate43.errors = [{instancePath,schemaPath:"#/additionalProperties",keyword:"additionalProperties",params:{additionalProperty: key0},message:"must NOT have additional properties"}];
return false;
break;
}
}
if(_errs1 === errors){
if(data.index !== undefined){
let data0 = data.index;
const _errs2 = errors;
if(!(((typeof data0 == "number") && (!(data0 % 1) && !isNaN(data0))) && (isFinite(data0)))){
validate43.errors = [{instancePath:instancePath+"/index",schemaPath:"#/properties/index/type",keyword:"type",params:{type: "integer"},message:"must be integer"}];
return false;
}
if(errors === _errs2){
if((typeof data0 == "number") && (isFinite(data0))){
if(data0 < 0 || isNaN(data0)){
validate43.errors = [{instancePath:instancePath+"/index",schemaPath:"#/properties/index/minimum",keyword:"minimum",params:{comparison: ">=", limit: 0},message:"must be >= 0"}];
return false;
}
}
}
var valid0 = _errs2 === errors;
}
else {
var valid0 = true;
}
if(valid0){
if(data.text !== undefined){
let data1 = data.text;
const _errs4 = errors;
if(errors === _errs4){
if(typeof data1 === "string"){
if(func1(data1) < 1){
validate43.errors = [{instancePath:instancePath+"/text",schemaPath:"#/properties/text/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
}
else {
validate43.errors = [{instancePath:instancePath+"/text",schemaPath:"#/properties/text/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid0 = _errs4 === errors;
}
else {
var valid0 = true;
}
if(valid0){
if(data.normalizedText !== undefined){
let data2 = data.normalizedText;
const _errs6 = errors;
if(errors === _errs6){
if(typeof data2 === "string"){
if(func1(data2) < 1){
validate43.errors = [{instancePath:instancePath+"/normalizedText",schemaPath:"#/properties/normalizedText/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
}
else {
validate43.errors = [{instancePath:instancePath+"/normalizedText",schemaPath:"#/properties/normalizedText/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid0 = _errs6 === errors;
}
else {
var valid0 = true;
}
if(valid0){
if(data.lemma !== undefined){
let data3 = data.lemma;
const _errs8 = errors;
if(errors === _errs8){
if(typeof data3 === "string"){
if(func1(data3) < 1){
validate43.errors = [{instancePath:instancePath+"/lemma",schemaPath:"#/properties/lemma/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
}
else {
validate43.errors = [{instancePath:instancePath+"/lemma",schemaPath:"#/properties/lemma/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid0 = _errs8 === errors;
}
else {
var valid0 = true;
}
if(valid0){
if(data.root !== undefined){
let data4 = data.root;
const _errs10 = errors;
if(errors === _errs10){
if(typeof data4 === "string"){
if(func1(data4) < 1){
validate43.errors = [{instancePath:instancePath+"/root",schemaPath:"#/properties/root/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
}
else {
validate43.errors = [{instancePath:instancePath+"/root",schemaPath:"#/properties/root/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid0 = _errs10 === errors;
}
else {
var valid0 = true;
}
if(valid0){
if(data.partOfSpeech !== undefined){
let data5 = data.partOfSpeech;
const _errs12 = errors;
if(errors === _errs12){
if(typeof data5 === "string"){
if(func1(data5) < 1){
validate43.errors = [{instancePath:instancePath+"/partOfSpeech",schemaPath:"#/properties/partOfSpeech/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
}
else {
validate43.errors = [{instancePath:instancePath+"/partOfSpeech",schemaPath:"#/properties/partOfSpeech/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid0 = _errs12 === errors;
}
else {
var valid0 = true;
}
if(valid0){
if(data.translation !== undefined){
let data6 = data.translation;
const _errs14 = errors;
if(errors === _errs14){
if(typeof data6 === "string"){
if(func1(data6) < 1){
validate43.errors = [{instancePath:instancePath+"/translation",schemaPath:"#/properties/translation/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
}
else {
validate43.errors = [{instancePath:instancePath+"/translation",schemaPath:"#/properties/translation/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid0 = _errs14 === errors;
}
else {
var valid0 = true;
}
if(valid0){
if(data.gloss !== undefined){
let data7 = data.gloss;
const _errs16 = errors;
if(errors === _errs16){
if(typeof data7 === "string"){
if(func1(data7) < 1){
validate43.errors = [{instancePath:instancePath+"/gloss",schemaPath:"#/properties/gloss/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
}
else {
validate43.errors = [{instancePath:instancePath+"/gloss",schemaPath:"#/properties/gloss/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid0 = _errs16 === errors;
}
else {
var valid0 = true;
}
if(valid0){
if(data.romanization !== undefined){
let data8 = data.romanization;
const _errs18 = errors;
if(errors === _errs18){
if(typeof data8 === "string"){
if(func1(data8) < 1){
validate43.errors = [{instancePath:instancePath+"/romanization",schemaPath:"#/properties/romanization/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
}
else {
validate43.errors = [{instancePath:instancePath+"/romanization",schemaPath:"#/properties/romanization/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid0 = _errs18 === errors;
}
else {
var valid0 = true;
}
if(valid0){
if(data.usageNote !== undefined){
let data9 = data.usageNote;
const _errs20 = errors;
if(errors === _errs20){
if(typeof data9 === "string"){
if(func1(data9) < 1){
validate43.errors = [{instancePath:instancePath+"/usageNote",schemaPath:"#/properties/usageNote/minLength",keyword:"minLength",params:{limit: 1},message:"must NOT have fewer than 1 characters"}];
return false;
}
}
else {
validate43.errors = [{instancePath:instancePath+"/usageNote",schemaPath:"#/properties/usageNote/type",keyword:"type",params:{type: "string"},message:"must be string"}];
return false;
}
}
var valid0 = _errs20 === errors;
}
else {
var valid0 = true;
}
}
}
}
}
}
}
}
}
}
}
}
}
else {
validate43.errors = [{instancePath,schemaPath:"#/type",keyword:"type",params:{type: "object"},message:"must be object"}];
return false;
}
}
validate43.errors = vErrors;
return errors === 0;
}
validate43.evaluated = {"props":true,"dynamicProps":false,"dynamicItems":false};

export const trackResponse = validate28;

export const updateInstanceSettings = validate44;
const schema56 = {"$schema":"https://json-schema.org/draft/2020-12/schema","$id":"https://transcribed-subtitle-extension.local/schemas/update-instance-settings.schema.json","title":"UpdateInstanceSettings","type":"object","additionalProperties":false,"properties":{"providers":{"type":"object","additionalProperties":false,"properties":{"openai":{"type":"object","additionalProperties":false,"properties":{"apiKey":{"type":["string","null"],"maxLength":4096,"pattern":"^[^\\u0000-\\u001F\\u007F]*$"}}},"cerebras":{"type":"object","additionalProperties":false,"properties":{"apiKey":{"type":["string","null"],"maxLength":4096,"pattern":"^[^\\u0000-\\u001F\\u007F]*$"}}},"elevenlabs":{"type":"object","additionalProperties":false,"properties":{"apiKey":{"type":["string","null"],"maxLength":4096,"pattern":"^[^\\u0000-\\u001F\\u007F]*$"}}},"claude":{"type":"object","additionalProperties":false,"properties":{"apiKey":{"type":["string","null"],"maxLength":4096,"pattern":"^[^\\u0000-\\u001F\\u007F]*$"}}},"anthropic":{"type":"object","additionalProperties":false,"properties":{"apiKey":{"type":["string","null"],"maxLength":4096,"pattern":"^[^\\u0000-\\u001F\\u007F]*$"}}}},"required":[]},"retentionDays":{"type":["integer","null"],"minimum":1,"maximum":36500}},"required":[]};
const pattern15 = new RegExp("^[^\\u0000-\\u001F\\u007F]*$", "u");

function validate44(data, {instancePath="", parentData, parentDataProperty, rootData=data, dynamicAnchors={}}={}){
/*# sourceURL="https://transcribed-subtitle-extension.local/schemas/update-instance-settings.schema.json" */;
let vErrors = null;
let errors = 0;
const evaluated0 = validate44.evaluated;
if(evaluated0.dynamicProps){
evaluated0.props = undefined;
}
if(evaluated0.dynamicItems){
evaluated0.items = undefined;
}
if(errors === 0){
if(data && typeof data == "object" && !Array.isArray(data)){
const _errs1 = errors;
for(const key0 in data){
if(!((key0 === "providers") || (key0 === "retentionDays"))){
validate44.errors = [{instancePath,schemaPath:"#/additionalProperties",keyword:"additionalProperties",params:{additionalProperty: key0},message:"must NOT have additional properties"}];
return false;
break;
}
}
if(_errs1 === errors){
if(data.providers !== undefined){
let data0 = data.providers;
const _errs2 = errors;
if(errors === _errs2){
if(data0 && typeof data0 == "object" && !Array.isArray(data0)){
const _errs4 = errors;
for(const key1 in data0){
if(!(((((key1 === "openai") || (key1 === "cerebras")) || (key1 === "elevenlabs")) || (key1 === "claude")) || (key1 === "anthropic"))){
validate44.errors = [{instancePath:instancePath+"/providers",schemaPath:"#/properties/providers/additionalProperties",keyword:"additionalProperties",params:{additionalProperty: key1},message:"must NOT have additional properties"}];
return false;
break;
}
}
if(_errs4 === errors){
if(data0.openai !== undefined){
let data1 = data0.openai;
const _errs5 = errors;
if(errors === _errs5){
if(data1 && typeof data1 == "object" && !Array.isArray(data1)){
const _errs7 = errors;
for(const key2 in data1){
if(!(key2 === "apiKey")){
validate44.errors = [{instancePath:instancePath+"/providers/openai",schemaPath:"#/properties/providers/properties/openai/additionalProperties",keyword:"additionalProperties",params:{additionalProperty: key2},message:"must NOT have additional properties"}];
return false;
break;
}
}
if(_errs7 === errors){
if(data1.apiKey !== undefined){
let data2 = data1.apiKey;
const _errs8 = errors;
if((typeof data2 !== "string") && (data2 !== null)){
validate44.errors = [{instancePath:instancePath+"/providers/openai/apiKey",schemaPath:"#/properties/providers/properties/openai/properties/apiKey/type",keyword:"type",params:{type: schema56.properties.providers.properties.openai.properties.apiKey.type},message:"must be string,null"}];
return false;
}
if(errors === _errs8){
if(typeof data2 === "string"){
if(func1(data2) > 4096){
validate44.errors = [{instancePath:instancePath+"/providers/openai/apiKey",schemaPath:"#/properties/providers/properties/openai/properties/apiKey/maxLength",keyword:"maxLength",params:{limit: 4096},message:"must NOT have more than 4096 characters"}];
return false;
}
else {
if(!pattern15.test(data2)){
validate44.errors = [{instancePath:instancePath+"/providers/openai/apiKey",schemaPath:"#/properties/providers/properties/openai/properties/apiKey/pattern",keyword:"pattern",params:{pattern: "^[^\\u0000-\\u001F\\u007F]*$"},message:"must match pattern \""+"^[^\\u0000-\\u001F\\u007F]*$"+"\""}];
return false;
}
}
}
}
}
}
}
else {
validate44.errors = [{instancePath:instancePath+"/providers/openai",schemaPath:"#/properties/providers/properties/openai/type",keyword:"type",params:{type: "object"},message:"must be object"}];
return false;
}
}
var valid1 = _errs5 === errors;
}
else {
var valid1 = true;
}
if(valid1){
if(data0.cerebras !== undefined){
let data3 = data0.cerebras;
const _errs10 = errors;
if(errors === _errs10){
if(data3 && typeof data3 == "object" && !Array.isArray(data3)){
const _errs12 = errors;
for(const key3 in data3){
if(!(key3 === "apiKey")){
validate44.errors = [{instancePath:instancePath+"/providers/cerebras",schemaPath:"#/properties/providers/properties/cerebras/additionalProperties",keyword:"additionalProperties",params:{additionalProperty: key3},message:"must NOT have additional properties"}];
return false;
break;
}
}
if(_errs12 === errors){
if(data3.apiKey !== undefined){
let data4 = data3.apiKey;
const _errs13 = errors;
if((typeof data4 !== "string") && (data4 !== null)){
validate44.errors = [{instancePath:instancePath+"/providers/cerebras/apiKey",schemaPath:"#/properties/providers/properties/cerebras/properties/apiKey/type",keyword:"type",params:{type: schema56.properties.providers.properties.cerebras.properties.apiKey.type},message:"must be string,null"}];
return false;
}
if(errors === _errs13){
if(typeof data4 === "string"){
if(func1(data4) > 4096){
validate44.errors = [{instancePath:instancePath+"/providers/cerebras/apiKey",schemaPath:"#/properties/providers/properties/cerebras/properties/apiKey/maxLength",keyword:"maxLength",params:{limit: 4096},message:"must NOT have more than 4096 characters"}];
return false;
}
else {
if(!pattern15.test(data4)){
validate44.errors = [{instancePath:instancePath+"/providers/cerebras/apiKey",schemaPath:"#/properties/providers/properties/cerebras/properties/apiKey/pattern",keyword:"pattern",params:{pattern: "^[^\\u0000-\\u001F\\u007F]*$"},message:"must match pattern \""+"^[^\\u0000-\\u001F\\u007F]*$"+"\""}];
return false;
}
}
}
}
}
}
}
else {
validate44.errors = [{instancePath:instancePath+"/providers/cerebras",schemaPath:"#/properties/providers/properties/cerebras/type",keyword:"type",params:{type: "object"},message:"must be object"}];
return false;
}
}
var valid1 = _errs10 === errors;
}
else {
var valid1 = true;
}
if(valid1){
if(data0.elevenlabs !== undefined){
let data5 = data0.elevenlabs;
const _errs15 = errors;
if(errors === _errs15){
if(data5 && typeof data5 == "object" && !Array.isArray(data5)){
const _errs17 = errors;
for(const key4 in data5){
if(!(key4 === "apiKey")){
validate44.errors = [{instancePath:instancePath+"/providers/elevenlabs",schemaPath:"#/properties/providers/properties/elevenlabs/additionalProperties",keyword:"additionalProperties",params:{additionalProperty: key4},message:"must NOT have additional properties"}];
return false;
break;
}
}
if(_errs17 === errors){
if(data5.apiKey !== undefined){
let data6 = data5.apiKey;
const _errs18 = errors;
if((typeof data6 !== "string") && (data6 !== null)){
validate44.errors = [{instancePath:instancePath+"/providers/elevenlabs/apiKey",schemaPath:"#/properties/providers/properties/elevenlabs/properties/apiKey/type",keyword:"type",params:{type: schema56.properties.providers.properties.elevenlabs.properties.apiKey.type},message:"must be string,null"}];
return false;
}
if(errors === _errs18){
if(typeof data6 === "string"){
if(func1(data6) > 4096){
validate44.errors = [{instancePath:instancePath+"/providers/elevenlabs/apiKey",schemaPath:"#/properties/providers/properties/elevenlabs/properties/apiKey/maxLength",keyword:"maxLength",params:{limit: 4096},message:"must NOT have more than 4096 characters"}];
return false;
}
else {
if(!pattern15.test(data6)){
validate44.errors = [{instancePath:instancePath+"/providers/elevenlabs/apiKey",schemaPath:"#/properties/providers/properties/elevenlabs/properties/apiKey/pattern",keyword:"pattern",params:{pattern: "^[^\\u0000-\\u001F\\u007F]*$"},message:"must match pattern \""+"^[^\\u0000-\\u001F\\u007F]*$"+"\""}];
return false;
}
}
}
}
}
}
}
else {
validate44.errors = [{instancePath:instancePath+"/providers/elevenlabs",schemaPath:"#/properties/providers/properties/elevenlabs/type",keyword:"type",params:{type: "object"},message:"must be object"}];
return false;
}
}
var valid1 = _errs15 === errors;
}
else {
var valid1 = true;
}
if(valid1){
if(data0.claude !== undefined){
let data7 = data0.claude;
const _errs20 = errors;
if(errors === _errs20){
if(data7 && typeof data7 == "object" && !Array.isArray(data7)){
const _errs22 = errors;
for(const key5 in data7){
if(!(key5 === "apiKey")){
validate44.errors = [{instancePath:instancePath+"/providers/claude",schemaPath:"#/properties/providers/properties/claude/additionalProperties",keyword:"additionalProperties",params:{additionalProperty: key5},message:"must NOT have additional properties"}];
return false;
break;
}
}
if(_errs22 === errors){
if(data7.apiKey !== undefined){
let data8 = data7.apiKey;
const _errs23 = errors;
if((typeof data8 !== "string") && (data8 !== null)){
validate44.errors = [{instancePath:instancePath+"/providers/claude/apiKey",schemaPath:"#/properties/providers/properties/claude/properties/apiKey/type",keyword:"type",params:{type: schema56.properties.providers.properties.claude.properties.apiKey.type},message:"must be string,null"}];
return false;
}
if(errors === _errs23){
if(typeof data8 === "string"){
if(func1(data8) > 4096){
validate44.errors = [{instancePath:instancePath+"/providers/claude/apiKey",schemaPath:"#/properties/providers/properties/claude/properties/apiKey/maxLength",keyword:"maxLength",params:{limit: 4096},message:"must NOT have more than 4096 characters"}];
return false;
}
else {
if(!pattern15.test(data8)){
validate44.errors = [{instancePath:instancePath+"/providers/claude/apiKey",schemaPath:"#/properties/providers/properties/claude/properties/apiKey/pattern",keyword:"pattern",params:{pattern: "^[^\\u0000-\\u001F\\u007F]*$"},message:"must match pattern \""+"^[^\\u0000-\\u001F\\u007F]*$"+"\""}];
return false;
}
}
}
}
}
}
}
else {
validate44.errors = [{instancePath:instancePath+"/providers/claude",schemaPath:"#/properties/providers/properties/claude/type",keyword:"type",params:{type: "object"},message:"must be object"}];
return false;
}
}
var valid1 = _errs20 === errors;
}
else {
var valid1 = true;
}
if(valid1){
if(data0.anthropic !== undefined){
let data9 = data0.anthropic;
const _errs25 = errors;
if(errors === _errs25){
if(data9 && typeof data9 == "object" && !Array.isArray(data9)){
const _errs27 = errors;
for(const key6 in data9){
if(!(key6 === "apiKey")){
validate44.errors = [{instancePath:instancePath+"/providers/anthropic",schemaPath:"#/properties/providers/properties/anthropic/additionalProperties",keyword:"additionalProperties",params:{additionalProperty: key6},message:"must NOT have additional properties"}];
return false;
break;
}
}
if(_errs27 === errors){
if(data9.apiKey !== undefined){
let data10 = data9.apiKey;
const _errs28 = errors;
if((typeof data10 !== "string") && (data10 !== null)){
validate44.errors = [{instancePath:instancePath+"/providers/anthropic/apiKey",schemaPath:"#/properties/providers/properties/anthropic/properties/apiKey/type",keyword:"type",params:{type: schema56.properties.providers.properties.anthropic.properties.apiKey.type},message:"must be string,null"}];
return false;
}
if(errors === _errs28){
if(typeof data10 === "string"){
if(func1(data10) > 4096){
validate44.errors = [{instancePath:instancePath+"/providers/anthropic/apiKey",schemaPath:"#/properties/providers/properties/anthropic/properties/apiKey/maxLength",keyword:"maxLength",params:{limit: 4096},message:"must NOT have more than 4096 characters"}];
return false;
}
else {
if(!pattern15.test(data10)){
validate44.errors = [{instancePath:instancePath+"/providers/anthropic/apiKey",schemaPath:"#/properties/providers/properties/anthropic/properties/apiKey/pattern",keyword:"pattern",params:{pattern: "^[^\\u0000-\\u001F\\u007F]*$"},message:"must match pattern \""+"^[^\\u0000-\\u001F\\u007F]*$"+"\""}];
return false;
}
}
}
}
}
}
}
else {
validate44.errors = [{instancePath:instancePath+"/providers/anthropic",schemaPath:"#/properties/providers/properties/anthropic/type",keyword:"type",params:{type: "object"},message:"must be object"}];
return false;
}
}
var valid1 = _errs25 === errors;
}
else {
var valid1 = true;
}
}
}
}
}
}
}
else {
validate44.errors = [{instancePath:instancePath+"/providers",schemaPath:"#/properties/providers/type",keyword:"type",params:{type: "object"},message:"must be object"}];
return false;
}
}
var valid0 = _errs2 === errors;
}
else {
var valid0 = true;
}
if(valid0){
if(data.retentionDays !== undefined){
let data11 = data.retentionDays;
const _errs30 = errors;
if((!(((typeof data11 == "number") && (!(data11 % 1) && !isNaN(data11))) && (isFinite(data11)))) && (data11 !== null)){
validate44.errors = [{instancePath:instancePath+"/retentionDays",schemaPath:"#/properties/retentionDays/type",keyword:"type",params:{type: schema56.properties.retentionDays.type},message:"must be integer,null"}];
return false;
}
if(errors === _errs30){
if((typeof data11 == "number") && (isFinite(data11))){
if(data11 > 36500 || isNaN(data11)){
validate44.errors = [{instancePath:instancePath+"/retentionDays",schemaPath:"#/properties/retentionDays/maximum",keyword:"maximum",params:{comparison: "<=", limit: 36500},message:"must be <= 36500"}];
return false;
}
else {
if(data11 < 1 || isNaN(data11)){
validate44.errors = [{instancePath:instancePath+"/retentionDays",schemaPath:"#/properties/retentionDays/minimum",keyword:"minimum",params:{comparison: ">=", limit: 1},message:"must be >= 1"}];
return false;
}
}
}
}
var valid0 = _errs30 === errors;
}
else {
var valid0 = true;
}
}
}
}
else {
validate44.errors = [{instancePath,schemaPath:"#/type",keyword:"type",params:{type: "object"},message:"must be object"}];
return false;
}
}
validate44.errors = vErrors;
return errors === 0;
}
validate44.evaluated = {"props":true,"dynamicProps":false,"dynamicItems":false};

export const okResponse = validate45;
const schema57 = {"type":"object","required":["ok"],"properties":{"ok":{"const":true}},"additionalProperties":false};

function validate45(data, {instancePath="", parentData, parentDataProperty, rootData=data, dynamicAnchors={}}={}){
let vErrors = null;
let errors = 0;
const evaluated0 = validate45.evaluated;
if(evaluated0.dynamicProps){
evaluated0.props = undefined;
}
if(evaluated0.dynamicItems){
evaluated0.items = undefined;
}
if(errors === 0){
if(data && typeof data == "object" && !Array.isArray(data)){
let missing0;
if((data.ok === undefined) && (missing0 = "ok")){
validate45.errors = [{instancePath,schemaPath:"#/required",keyword:"required",params:{missingProperty: missing0},message:"must have required property '"+missing0+"'"}];
return false;
}
else {
const _errs1 = errors;
for(const key0 in data){
if(!(key0 === "ok")){
validate45.errors = [{instancePath,schemaPath:"#/additionalProperties",keyword:"additionalProperties",params:{additionalProperty: key0},message:"must NOT have additional properties"}];
return false;
break;
}
}
if(_errs1 === errors){
if(data.ok !== undefined){
if(true !== data.ok){
validate45.errors = [{instancePath:instancePath+"/ok",schemaPath:"#/properties/ok/const",keyword:"const",params:{allowedValue: true},message:"must be equal to constant"}];
return false;
}
}
}
}
}
else {
validate45.errors = [{instancePath,schemaPath:"#/type",keyword:"type",params:{type: "object"},message:"must be object"}];
return false;
}
}
validate45.errors = vErrors;
return errors === 0;
}
validate45.evaluated = {"props":true,"dynamicProps":false,"dynamicItems":false};
